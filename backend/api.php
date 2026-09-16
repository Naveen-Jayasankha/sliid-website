<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = $config['cors_origins'] ?? [];
if ($origin && in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if ($origin && !in_array($origin, $allowed, true)) json_response(['ok'=>false,'message'=>'Origin not allowed.'], 403);
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

try {
    if ($resource === 'health' && $method === 'GET') {
        db()->query('SELECT 1'); json_response(['ok'=>true,'service'=>'SLIID API','time'=>date(DATE_ATOM)]);
    }
    if ($resource === 'members' && $method === 'GET') {
        [$page,$limit,$offset] = page_args(); $where=["status='active'"]; $params=[];
        if ($q=trim((string)($_GET['q']??''))) { $where[]='(name LIKE ? OR membership_number LIKE ? OR specialisations LIKE ?)'; $term="%$q%"; array_push($params,$term,$term,$term); }
        if ($cat=trim((string)($_GET['category']??''))) { $where[]='category=?'; $params[]=$cat; }
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
        [$page,$limit,$offset]=page_args();$past=($_GET['past']??'0')==='1';$date=$past?'starts_at<NOW()':'starts_at>=NOW()';$order=$past?'DESC':'ASC';$count=db()->query("SELECT COUNT(*) FROM events WHERE status='published' AND $date")->fetchColumn();$stmt=db()->query("SELECT id,title,slug,summary,venue,starts_at,ends_at,registration_url,image_path,featured FROM events WHERE status='published' AND $date ORDER BY featured DESC,starts_at $order LIMIT $limit OFFSET $offset");$rows=$stmt->fetchAll();foreach($rows as &$row)$row['image_url']=public_url($row['image_path']);json_response(['ok'=>true,'data'=>$rows,'pagination'=>['page'=>$page,'limit'=>$limit,'total'=>(int)$count]]);
    }
    if ($resource === 'publications' && $method === 'GET') {
        [$page,$limit,$offset]=page_args();$count=db()->query("SELECT COUNT(*) FROM publications WHERE status='published'")->fetchColumn();$stmt=db()->query("SELECT id,title,description,category,file_path,external_url,published_at FROM publications WHERE status='published' ORDER BY published_at DESC,created_at DESC LIMIT $limit OFFSET $offset");$rows=$stmt->fetchAll();foreach($rows as &$row)$row['file_url']=public_url($row['file_path']);json_response(['ok'=>true,'data'=>$rows,'pagination'=>['page'=>$page,'limit'=>$limit,'total'=>(int)$count]]);
    }
    if ($resource === 'contact' && $method === 'POST') {
        rate_limit_submissions();$data=json_input();required($data,['name','email','subject','message']);if(!filter_var($data['email'],FILTER_VALIDATE_EMAIL))json_response(['ok'=>false,'message'=>'Enter a valid email address.'],422);
        $stmt=db()->prepare('INSERT INTO enquiries(name,email,phone,subject,message,source_ip_hash) VALUES(?,?,?,?,?,?)');$stmt->execute([trim($data['name']),strtolower(trim($data['email'])),trim((string)($data['phone']??'')),trim($data['subject']),trim($data['message']),client_hash()]);json_response(['ok'=>true,'message'=>'Thank you. Your enquiry has been received.'],201);
    }
    if ($resource === 'membership-applications' && $method === 'POST') {
        rate_limit_submissions();$data=$_POST ?: json_input();required($data,['name','email','phone','category']);if(!filter_var($data['email'],FILTER_VALIDATE_EMAIL))json_response(['ok'=>false,'message'=>'Enter a valid email address.'],422);
        $document=upload_file('document',['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'],'documents');$stmt=db()->prepare('INSERT INTO membership_applications(name,email,phone,category,qualification,experience_years,organisation,message,document_path,source_ip_hash) VALUES(?,?,?,?,?,?,?,?,?,?)');$stmt->execute([trim($data['name']),strtolower(trim($data['email'])),trim($data['phone']),trim($data['category']),trim((string)($data['qualification']??'')),($data['experience_years']??'')!==''?(int)$data['experience_years']:null,trim((string)($data['organisation']??'')),trim((string)($data['message']??'')),$document,client_hash()]);json_response(['ok'=>true,'message'=>'Your membership application has been received.'],201);
    }
    json_response(['ok'=>false,'message'=>'Endpoint not found.'],404);
} catch (Throwable $e) {
    if (!empty($config['debug'])) json_response(['ok'=>false,'message'=>$e->getMessage()],500);
    error_log($e->__toString());json_response(['ok'=>false,'message'=>'A server error occurred.'],500);
}
