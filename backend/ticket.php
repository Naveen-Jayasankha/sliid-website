<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
ensure_booking_schema();
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

$token = strtolower(trim((string)($_GET['token'] ?? '')));
$ticket = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $stmt = db()->prepare('SELECT t.ticket_number,t.token,t.status ticket_status,t.checked_in_at,p.name,p.email,p.phone,p.organisation,p.designation,p.attendee_type,p.membership_number,p.nic,p.unit_price,b.reference,b.status booking_status,b.currency,e.title,e.venue,e.starts_at,e.ends_at,e.image_path FROM event_tickets t JOIN event_booking_participants p ON p.id=t.participant_id JOIN event_bookings b ON b.id=t.booking_id JOIN events e ON e.id=b.event_id WHERE t.token=? LIMIT 1');
    $stmt->execute([$token]);
    $ticket = $stmt->fetch() ?: null;
}
if (!$ticket) { http_response_code(404); }
$isMember = $ticket && $ticket['attendee_type'] === 'member';
$isValid = $ticket && $ticket['booking_status'] === 'confirmed' && $ticket['ticket_status'] !== 'void';
$eventImage = $ticket ? public_url($ticket['image_path']) : null;
$dateLabel = $ticket ? date('l, F j, Y', strtotime($ticket['starts_at'])) : '';
$timeLabel = $ticket ? date('g:i A', strtotime($ticket['starts_at'])) . (!empty($ticket['ends_at']) ? ' – ' . date('g:i A', strtotime($ticket['ends_at'])) : '') : '';
$identity = '';
if ($ticket) {
    $identity = $isMember ? (string)($ticket['membership_number'] ?: '—') : (string)($ticket['nic'] ?: '—');
    if (!$isMember && strlen($identity) > 4) $identity = '•••• ' . substr($identity, -4);
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?=e($ticket ? $ticket['ticket_number'] . ' | SLIID e-ticket' : 'Ticket not found')?></title><link rel="icon" href="assets/sliid-favicon.png"><link rel="stylesheet" href="assets/ticket.css?v=20261120-1"></head>
<body class="ticket-page <?=$isMember?'member-theme':'guest-theme'?>">
<?php if(!$ticket):?><main class="ticket-error"><h1>Ticket not found</h1><p>This ticket link is invalid or incomplete.</p></main>
<?php else:?><main class="ticket-shell">
  <div class="ticket-actions"><button type="button" onclick="window.print()">Print / Save PDF</button></div>
  <article class="eticket">
    <section class="ticket-visual">
      <?php if($eventImage):?><img src="<?=e($eventImage)?>" alt="<?=e($ticket['title'])?> event artwork"><?php endif?>
      <div class="visual-shade"></div><div class="visual-brand"><img src="../assets/sliid-official-logo.webp" alt="SLIID"><span>Official event e-ticket</span></div>
      <div class="visual-copy"><span class="ticket-type"><?=$isMember?'Member ticket':'Non-member ticket'?></span><h1><?=e($ticket['title'])?></h1><p><?=e($dateLabel)?> · <?=e($ticket['venue'] ?: 'Venue to be confirmed')?></p></div>
    </section>
    <section class="ticket-information">
      <div class="ticket-topline"><div><span>Admit one</span><strong><?=e($ticket['ticket_number'])?></strong></div><span class="ticket-state <?=$isValid?'valid':'invalid'?>"><?=$isValid?($ticket['ticket_status']==='checked_in'?'Checked in':'Valid ticket'):'Not valid'?></span></div>
      <div class="attendee"><span>Attendee</span><h2><?=e($ticket['name'])?></h2><p><?=e($isMember ? 'SLIID member' : 'Non-member')?><?=!empty($ticket['organisation'])?' · '.e($ticket['organisation']):''?></p></div>
      <dl class="ticket-details"><div><dt>Date</dt><dd><?=e($dateLabel)?></dd></div><div><dt>Time</dt><dd><?=e($timeLabel)?></dd></div><div><dt>Venue</dt><dd><?=e($ticket['venue'] ?: 'To be confirmed')?></dd></div><div><dt><?=$isMember?'Membership no.':'NIC'?></dt><dd><?=e($identity)?></dd></div></dl>
      <div class="ticket-code"><div id="ticket-qr" data-value="<?=e(ticket_validation_url($ticket['token']))?>" aria-label="Ticket validation QR code"></div><div><span>Scan at entrance</span><strong><?=e($ticket['ticket_number'])?></strong><small>Booking <?=e($ticket['reference'])?></small></div></div>
      <p class="ticket-note">Present this e-ticket on your phone or as a printed copy. This ticket is unique and can be checked in only once.</p>
    </section>
  </article>
</main><script src="assets/qrcode.min.js"></script><script>const el=document.getElementById('ticket-qr');if(el&&window.QRCode)new QRCode(el,{text:el.dataset.value,width:180,height:180,colorDark:'#071c29',colorLight:'#ffffff',correctLevel:QRCode.CorrectLevel.H});</script><?php endif?>
</body></html>
