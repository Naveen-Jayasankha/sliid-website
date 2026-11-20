<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
ensure_booking_schema();
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

$token = strtolower(trim((string)($_GET['token'] ?? $_POST['token'] ?? '')));
$number = strtoupper(trim((string)($_GET['ticket'] ?? $_POST['ticket'] ?? '')));
$notice = '';
$error = '';

function find_event_ticket(string $token, string $number): ?array {
    $base = 'SELECT t.*,p.name,p.email,p.phone,p.organisation,p.designation,p.attendee_type,p.membership_number,p.nic,b.reference,b.status booking_status,e.title,e.venue,e.starts_at,e.ends_at FROM event_tickets t JOIN event_booking_participants p ON p.id=t.participant_id JOIN event_bookings b ON b.id=t.booking_id JOIN events e ON e.id=b.event_id WHERE ';
    if (preg_match('/^[a-f0-9]{64}$/', $token)) { $stmt=db()->prepare($base.'t.token=? LIMIT 1');$stmt->execute([$token]); }
    elseif (preg_match('/^SLIID-[A-Z0-9-]{6,32}$/', $number)) { $stmt=db()->prepare($base.'t.ticket_number=? LIMIT 1');$stmt->execute([$number]); }
    else return null;
    return $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_in') {
    if (!admin_user()) { $error = 'Administrator sign-in is required to check in an attendee.'; }
    else {
        verify_csrf();
        $current = find_event_ticket($token, $number);
        if (!$current || $current['booking_status'] !== 'confirmed' || $current['status'] === 'void') $error = 'This ticket is not valid for entry.';
        elseif ($current['status'] === 'checked_in') $error = 'This ticket was already checked in at ' . date('g:i A, F j, Y', strtotime($current['checked_in_at'])) . '.';
        else {
            $stmt=db()->prepare("UPDATE event_tickets SET status='checked_in',checked_in_at=NOW(),checked_in_by=? WHERE id=? AND status='issued'");
            $stmt->execute([(int)admin_user()['id'],(int)$current['id']]);
            if($stmt->rowCount()){audit('check_in','event_ticket',(int)$current['id'],['ticket_number'=>$current['ticket_number']]);$notice='Entry confirmed. Welcome, '.$current['name'].'!';}
            else $error='This ticket could not be checked in. Refresh and try again.';
        }
    }
}
$ticket = find_event_ticket($token, $number);
$valid = $ticket && $ticket['booking_status'] === 'confirmed' && $ticket['status'] !== 'void';
$alreadyUsed = $valid && $ticket['status'] === 'checked_in';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>SLIID Ticket Validator</title><link rel="icon" href="assets/sliid-favicon.png"><link rel="stylesheet" href="assets/ticket.css?v=20261120-1"></head><body class="validator-page"><main class="validator-shell"><header><b>SLIID</b><span>Entrance ticket validator</span></header><form class="validator-search" method="get"><label>Scan QR or enter ticket number<input name="ticket" value="<?=e($number)?>" placeholder="SLIID-26-TXXXXXXXX" autocomplete="off"></label><button>Validate</button></form><?php if($notice):?><div class="validator-message success"><?=e($notice)?></div><?php endif?><?php if($error):?><div class="validator-message error"><?=e($error)?></div><?php endif?><?php if($ticket):?><section class="validation-result <?=$valid?'valid':'invalid'?>"><div class="validation-icon"><?=$valid?($alreadyUsed?'!':'✓'):'×'?></div><span><?=$valid?($alreadyUsed?'Already checked in':'Valid e-ticket'):'Invalid e-ticket'?></span><h1><?=e($ticket['name'])?></h1><p><?=e($ticket['title'])?></p><dl><div><dt>Ticket</dt><dd><?=e($ticket['ticket_number'])?></dd></div><div><dt>Type</dt><dd><?=e($ticket['attendee_type']==='member'?'SLIID member':'Non-member')?></dd></div><div><dt>Date</dt><dd><?=e(date('F j, Y · g:i A',strtotime($ticket['starts_at'])))?></dd></div><div><dt>Venue</dt><dd><?=e($ticket['venue']?:'To be confirmed')?></dd></div><?php if(admin_user()):?><div><dt><?=$ticket['attendee_type']==='member'?'Membership no.':'NIC'?></dt><dd><?=e($ticket['attendee_type']==='member'?($ticket['membership_number']?:'—'):($ticket['nic']?:'—'))?></dd></div><?php endif?></dl><?php if($valid&&!$alreadyUsed&&admin_user()):?><form method="post" class="checkin-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="check_in"><input type="hidden" name="token" value="<?=e($ticket['token'])?>"><button>Confirm entrance check-in</button></form><?php elseif(!admin_user()):?><p class="admin-hint"><a href="admin.php">Sign in to the administration panel</a> on this device to record entrance check-ins.</p><?php elseif($alreadyUsed):?><p class="used-at">Checked in <?=e(date('F j, Y · g:i A',strtotime($ticket['checked_in_at'])))?></p><?php endif?></section><?php elseif($token!==''||$number!==''):?><section class="validation-result invalid"><div class="validation-icon">×</div><span>Ticket not found</span><h1>Do not admit</h1><p>Check the printed number or ask the attendee to open the original ticket email.</p></section><?php endif?><footer><a href="admin.php?section=ticket-validation">Back to administration</a></footer></main></body></html>
