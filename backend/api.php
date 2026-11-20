<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));

function normalise_origin(string $value): string {
    $value = rtrim(trim($value), '/');
    $parts = parse_url($value);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return '';
    $scheme = strtolower((string)$parts['scheme']);
    $host = strtolower((string)$parts['host']);
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    return $scheme . '://' . $host . $port;
}

$allowed = is_array($config['cors_origins'] ?? null) ? $config['cors_origins'] : [];
$appOrigin = normalise_origin((string)($config['app_url'] ?? ''));
if ($appOrigin !== '') {
    $allowed[] = $appOrigin;
    $parts = parse_url($appOrigin);
    $host = strtolower((string)($parts['host'] ?? ''));
    $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    if ($host !== '') {
        $alternateHost = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
        $allowed[] = $scheme . '://' . $alternateHost . $port;
    }
}
$allowed = array_values(array_unique(array_filter(array_map('normalise_origin', $allowed))));
$normalisedOrigin = normalise_origin($origin);
$originAllowed = $normalisedOrigin !== '' && in_array($normalisedOrigin, $allowed, true);

if ($origin && $originAllowed) {
    header('Access-Control-Allow-Origin: ' . $normalisedOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if ($origin && !$originAllowed) {
    error_log('Blocked CORS origin: ' . $origin);
    json_response(['ok'=>false,'message'=>'Origin not allowed.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
header('Cache-Control: no-store');

$resource = strtolower((string)($_GET['resource'] ?? 'health'));
$method = $_SERVER['REQUEST_METHOD'];

function page_args(): array {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 12)));
    return [$page, $limit, ($page - 1) * $limit];
}
function required(array $data, array $fields): void {
    foreach ($fields as $field) if (trim((string)($data[$field] ?? '')) === '') json_response(['ok'=>false,'message'=>'Please complete all required fields.','field'=>$field], 422);
}
function client_hash(): string { return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|sliid-submission'); }
function rate_limit_submissions(): void {
    $hash = client_hash();
    $stmt = db()->prepare("SELECT (SELECT COUNT(*) FROM enquiries WHERE source_ip_hash=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 HOUR)) + (SELECT COUNT(*) FROM membership_applications WHERE source_ip_hash=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 HOUR)) AS total");
    $stmt->execute([$hash, $hash]);
    if ((int)$stmt->fetchColumn() >= 8) json_response(['ok'=>false,'message'=>'Too many submissions. Please try again later.'], 429);
}
function rate_limit_bookings(): void {
    ensure_booking_schema();
    $stmt=db()->prepare("SELECT COUNT(*) FROM event_bookings WHERE source_ip_hash=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 HOUR)");
    $stmt->execute([client_hash()]);
    if((int)$stmt->fetchColumn()>=5)json_response(['ok'=>false,'message'=>'Too many booking attempts. Please try again later.'],429);
}

try {
    if ($resource === 'health' && $method === 'GET') {
        db()->query('SELECT 1'); json_response(['ok'=>true,'service'=>'SLIID API','time'=>date(DATE_ATOM)]);
    }
    if ($resource === 'members' && $method === 'GET') {
        [$page,$limit,$offset] = page_args(); $where=["status='active'"]; $params=[];
        if ($q=trim((string)($_GET['q']??''))) { $where[]='(name LIKE ? OR membership_number LIKE ? OR specialisations LIKE ?)'; $term="%$q%"; array_push($params,$term,$term,$term); }
        if ($cat=trim((string)($_GET['category']??''))) { $where[]='category=?'; $params[]=$cat; }
        if ($spec=trim((string)($_GET['specialisation']??''))) { $where[]='specialisations LIKE ?'; $params[]="%$spec%"; }
        if ($loc=trim((string)($_GET['location']??''))) { $where[]='location LIKE ?'; $params[]="%$loc%"; }
        $sql=' FROM members WHERE '.implode(' AND ',$where); $count=db()->prepare('SELECT COUNT(*)'.$sql); $count->execute($params);
        $stmt=db()->prepare('SELECT id,name,membership_number,category,location,specialisations,bio,website,image_path,featured,created_at'.$sql.' ORDER BY featured DESC,name ASC LIMIT '.$limit.' OFFSET '.$offset); $stmt->execute($params); $rows=$stmt->fetchAll();
        foreach($rows as &$row) $row['image_url']=public_url($row['image_path']);
        json_response(['ok'=>true,'data'=>$rows,'pagination'=>['page'=>$page,'limit'=>$limit,'total'=>(int)$count->fetchColumn()]]);
    }
    if ($resource === 'news' && $method === 'GET') {
        [$page,$limit,$offset]=page_args(); $params=[]; $where="status='published' AND (published_at IS NULL OR published_at<=NOW())";
        if($slug=trim((string)($_GET['slug']??''))){$stmt=db()->prepare("SELECT * FROM news WHERE $where AND slug=? LIMIT 1");$stmt->execute([$slug]);$row=$stmt->fetch();if(!$row)json_response(['ok'=>false,'message'=>'Article not found.'],404);$row['image_url']=public_url($row['image_path']);json_response(['ok'=>true,'data'=>$row]);}
        $count=db()->query("SELECT COUNT(*) FROM news WHERE $where")->fetchColumn();$stmt=db()->query("SELECT id,title,slug,excerpt,category,image_path,published_at FROM news WHERE $where ORDER BY published_at DESC,created_at DESC LIMIT $limit OFFSET $offset");$rows=$stmt->fetchAll();foreach($rows as &$row)$row['image_url']=public_url($row['image_path']);json_response(['ok'=>true,'data'=>$rows,'pagination'=>['page'=>$page,'limit'=>$limit,'total'=>(int)$count]]);
    }
    if ($resource === 'events' && $method === 'GET') {
        [$page,$limit,$offset]=page_args();$past=($_GET['past']??'0')==='1';$date=$past?'starts_at<NOW()':'starts_at>=NOW()';$order=$past?'DESC':'ASC';
        $optionalColumns=[
            'tags'=>"NULL AS tags",
            'booking_enabled'=>"0 AS booking_enabled",
            'pricing_type'=>"'free' AS pricing_type",
            'member_price'=>"0 AS member_price",
            'non_member_price'=>"0 AS non_member_price",
            'currency'=>"'LKR' AS currency",
            'capacity'=>"NULL AS capacity",
            'bank_transfer_enabled'=>"0 AS bank_transfer_enabled",
            'bank_details'=>"NULL AS bank_details",
        ];
        $select=['id','title','slug','summary','description','venue','starts_at','ends_at','registration_url','image_path','featured'];
        foreach($optionalColumns as $column=>$fallback)$select[]=table_has_column('events',$column)?$column:$fallback;
        $count=db()->query("SELECT COUNT(*) FROM events WHERE status='published' AND $date")->fetchColumn();
        $stmt=db()->query('SELECT '.implode(',',$select)." FROM events WHERE status='published' AND $date ORDER BY featured DESC,starts_at $order LIMIT $limit OFFSET $offset");$rows=$stmt->fetchAll();
        $galleries=[];if($rows&&database_has_table('event_images')){$ids=array_column($rows,'id');$marks=implode(',',array_fill(0,count($ids),'?'));$galleryStmt=db()->prepare("SELECT event_id,image_path FROM event_images WHERE event_id IN ($marks) ORDER BY sort_order,id");$galleryStmt->execute($ids);foreach($galleryStmt->fetchAll() as $image)$galleries[(int)$image['event_id']][]=public_url($image['image_path']);}
        foreach($rows as &$row){$row['image_url']=public_url($row['image_path']);$row['gallery_images']=$galleries[(int)$row['id']]??[];}json_response(['ok'=>true,'data'=>$rows,'pagination'=>['page'=>$page,'limit'=>$limit,'total'=>(int)$count]]);
    }
    if ($resource === 'event-bookings' && $method === 'POST') {
        rate_limit_bookings();$data=$_POST ?: json_input();required($data,['event_id','purchaser_name','purchaser_email','purchaser_phone','participants']);
        if(!filter_var($data['purchaser_email'],FILTER_VALIDATE_EMAIL))json_response(['ok'=>false,'message'=>'Enter a valid purchaser email address.'],422);
        $participants=json_decode((string)$data['participants'],true);if(!is_array($participants)||count($participants)<1||count($participants)>10)json_response(['ok'=>false,'message'=>'Add between 1 and 10 participants.'],422);
        $eventId=(int)$data['event_id'];$eventStmt=db()->prepare("SELECT * FROM events WHERE id=? AND status='published' AND booking_enabled=1 AND starts_at>=NOW() LIMIT 1");$eventStmt->execute([$eventId]);$event=$eventStmt->fetch();if(!$event)json_response(['ok'=>false,'message'=>'This event is not currently accepting bookings.'],404);
        $normalised=[];$total=0.0;foreach($participants as $participant){
            $name=trim((string)($participant['name']??''));$email=strtolower(trim((string)($participant['email']??'')));$type=($participant['attendee_type']??'non_member')==='member'?'member':'non_member';$number=trim((string)($participant['membership_number']??''));$nic=strtoupper((string)preg_replace('/[\s-]+/','',trim((string)($participant['nic']??''))));
            if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL))json_response(['ok'=>false,'message'=>'Each participant needs a name and valid email.'],422);
            if($type==='member'&&$number==='')json_response(['ok'=>false,'message'=>'Enter the membership number for each member participant.'],422);
            if($type==='non_member'&&!preg_match('/^(?:\d{9}[VX]|\d{12})$/',$nic))json_response(['ok'=>false,'message'=>'Enter a valid NIC for each non-member participant.'],422);
            $price=$event['pricing_type']==='free'?0.0:(float)($type==='member'?$event['member_price']:$event['non_member_price']);$total+=$price;
            $normalised[]=['name'=>$name,'email'=>$email,'phone'=>trim((string)($participant['phone']??'')),'organisation'=>trim((string)($participant['organisation']??'')),'designation'=>trim((string)($participant['designation']??'')),'attendee_type'=>$type,'membership_number'=>$number,'nic'=>$type==='non_member'?$nic:null,'unit_price'=>$price];
        }
        if(!empty($event['capacity'])){$reservedStmt=db()->prepare("SELECT COALESCE(SUM(seats),0) FROM event_bookings WHERE event_id=? AND status NOT IN ('rejected','cancelled')");$reservedStmt->execute([$eventId]);if((int)$reservedStmt->fetchColumn()+count($normalised)>(int)$event['capacity'])json_response(['ok'=>false,'message'=>'There are not enough seats remaining for this booking.'],409);}
        $isFree=$event['pricing_type']==='free'||$total<=0;$method=$isFree?'free':'bank_transfer';
        if(!$isFree&&!$event['bank_transfer_enabled'])json_response(['ok'=>false,'message'=>'Online payment is not yet available for this event. Please contact SLIID.'],422);
        $receipt=null;if(!$isFree){$receipt=upload_file('receipt',['application/pdf'=>'pdf'],'receipts');if(!$receipt)json_response(['ok'=>false,'message'=>'Upload the bank transfer receipt as a PDF.'],422);}
        $reference='SLIID-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));$status=$isFree?'confirmed':'pending_verification';
        db()->beginTransaction();try{
            $stmt=db()->prepare('INSERT INTO event_bookings(reference,event_id,purchaser_name,purchaser_email,purchaser_phone,organisation,designation,seats,subtotal,currency,payment_method,receipt_path,status,source_ip_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$stmt->execute([$reference,$eventId,trim($data['purchaser_name']),strtolower(trim($data['purchaser_email'])),trim($data['purchaser_phone']),trim((string)($data['organisation']??'')),trim((string)($data['designation']??'')),count($normalised),$total,$event['currency'],$method,$receipt,$status,client_hash()]);$bookingId=(int)db()->lastInsertId();
            $participantStmt=db()->prepare('INSERT INTO event_booking_participants(booking_id,name,email,phone,organisation,designation,attendee_type,membership_number,nic,unit_price) VALUES(?,?,?,?,?,?,?,?,?,?)');foreach($normalised as $participant)$participantStmt->execute([$bookingId,$participant['name'],$participant['email'],$participant['phone'],$participant['organisation'],$participant['designation'],$participant['attendee_type'],$participant['membership_number'],$participant['nic'],$participant['unit_price']]);db()->commit();
        }catch(Throwable $e){db()->rollBack();throw $e;}
        if($status==='confirmed'){issue_tickets_for_booking($bookingId);send_ticket_email($bookingId);}
        json_response(['ok'=>true,'message'=>$isFree?'Your booking is confirmed.':'Your receipt has been submitted for verification.','reference'=>$reference,'status'=>$status,'amount'=>$total,'currency'=>$event['currency']],201);
    }
    if ($resource === 'publications' && $method === 'GET') {
        [$page,$limit,$offset]=page_args();$count=db()->query("SELECT COUNT(*) FROM publications WHERE status='published'")->fetchColumn();$stmt=db()->query("SELECT id,title,description,category,file_path,external_url,published_at FROM publications WHERE status='published' ORDER BY published_at DESC,created_at DESC LIMIT $limit OFFSET $offset");$rows=$stmt->fetchAll();foreach($rows as &$row)$row['file_url']=public_url($row['file_path']);json_response(['ok'=>true,'data'=>$rows,'pagination'=>['page'=>$page,'limit'=>$limit,'total'=>(int)$count]]);
    }
    if ($resource === 'contact' && $method === 'POST') {
        rate_limit_submissions();$data=json_input();required($data,['name','email','subject','message','consent']);if(!filter_var($data['email'],FILTER_VALIDATE_EMAIL))json_response(['ok'=>false,'message'=>'Enter a valid email address.'],422);
        $stmt=db()->prepare('INSERT INTO enquiries(name,email,phone,subject,message,consent_at,source_ip_hash) VALUES(?,?,?,?,?,NOW(),?)');$stmt->execute([trim($data['name']),strtolower(trim($data['email'])),trim((string)($data['phone']??'')),trim($data['subject']),trim($data['message']),client_hash()]);json_response(['ok'=>true,'message'=>'Thank you. Your enquiry has been received.'],201);
    }
    if ($resource === 'membership-applications' && $method === 'POST') {
        rate_limit_submissions();$data=$_POST ?: json_input();required($data,['name','email','phone','category','declaration','consent']);if(!filter_var($data['email'],FILTER_VALIDATE_EMAIL))json_response(['ok'=>false,'message'=>'Enter a valid email address.'],422);
        $document=upload_file('document',['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'],'documents');$stmt=db()->prepare('INSERT INTO membership_applications(name,email,phone,category,qualification,experience_years,organisation,message,document_path,declaration_at,consent_at,source_ip_hash) VALUES(?,?,?,?,?,?,?,?,?,NOW(),NOW(),?)');$stmt->execute([trim($data['name']),strtolower(trim($data['email'])),trim($data['phone']),trim($data['category']),trim((string)($data['qualification']??'')),($data['experience_years']??'')!==''?(int)$data['experience_years']:null,trim((string)($data['organisation']??'')),trim((string)($data['message']??'')),$document,client_hash()]);json_response(['ok'=>true,'message'=>'Your membership application has been received.'],201);
    }
    json_response(['ok'=>false,'message'=>'Endpoint not found.'],404);
} catch (Throwable $e) {
    if (!empty($config['debug'])) json_response(['ok'=>false,'message'=>$e->getMessage()],500);
    error_log($e->__toString());json_response(['ok'=>false,'message'=>'A server error occurred.'],500);
}
