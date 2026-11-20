<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(503);
    exit('Backend is not installed. Open install.php to continue.');
}

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Colombo');
if (!empty($config['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

session_name('sliid_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function db(): PDO {
    static $pdo;
    global $config;
    if ($pdo instanceof PDO) return $pdo;
    $d = $config['database'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $d['host'], $d['port'], $d['name'], $d['charset']);
    $pdo = new PDO($dsn, $d['user'], $d['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function json_input(): array {
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($type, 'application/json')) {
        $data = json_decode((string) file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}
function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function verify_csrf(): void {
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419); exit('Security token expired. Please refresh and try again.');
    }
}
function admin_user(): ?array { return $_SESSION['admin'] ?? null; }
function require_admin(): void {
    if (!admin_user()) { header('Location: admin.php'); exit; }
}
function slugify(string $text): string {
    $text = strtolower(trim(preg_replace('/[^\pL\pN]+/u', '-', $text), '-'));
    return $text !== '' ? $text : bin2hex(random_bytes(5));
}
function audit(string $action, string $entity, ?int $entityId = null, array $details = []): void {
    $user = admin_user();
    $stmt = db()->prepare('INSERT INTO audit_logs(admin_id, action, entity, entity_id, details, ip_address) VALUES(?,?,?,?,?,?)');
    $stmt->execute([$user['id'] ?? null, $action, $entity, $entityId, json_encode($details), $_SERVER['REMOTE_ADDR'] ?? null]);
}
function save_image_as_webp(string $source, string $mime, string $destination, int $quality = 82): bool {
    if (!function_exists('imagewebp')) return false;
    $image = match ($mime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($source) : false,
        'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($source) : false,
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
        default => false,
    };
    if (!$image) return false;
    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
    }
    $saved = @imagewebp($image, $destination, max(1, min(100, $quality)));
    imagedestroy($image);
    if (!$saved || !is_file($destination) || filesize($destination) === 0) {
        @unlink($destination);
        return false;
    }
    @chmod($destination, 0644);
    return true;
}
function upload_file(string $field, array $allowed, string $folder): ?string {
    global $config;
    if (empty($_FILES[$field]['name'])) return null;
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > ($config['upload_max_bytes'] ?? 5242880)) throw new RuntimeException('Invalid or oversized upload.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowed[$mime])) throw new RuntimeException('Unsupported file type.');
    $stem = bin2hex(random_bytes(16));
    $name = $stem . '.' . $allowed[$mime];
    $dir = __DIR__ . '/uploads/' . trim($folder, '/');
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Unable to create upload directory.');
    if (trim($folder, '/') === 'receipts' && !is_file($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    if (trim($folder, '/') === 'images' && in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        $webpName = $stem . '.webp';
        if (save_image_as_webp($file['tmp_name'], $mime, $dir . '/' . $webpName)) {
            return 'uploads/' . trim($folder, '/') . '/' . $webpName;
        }
    }
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Upload failed.');
    @chmod($dir . '/' . $name, 0644);
    return 'uploads/' . trim($folder, '/') . '/' . $name;
}
function upload_files(string $field, array $allowed, string $folder, int $maximum = 12): array {
    if (empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name'])) return [];
    $uploaded = [];
    $count = min(count($_FILES[$field]['name']), $maximum);
    for ($index = 0; $index < $count; $index++) {
        if ($_FILES[$field]['error'][$index] === UPLOAD_ERR_NO_FILE) continue;
        $_FILES['__batch_upload'] = [
            'name' => $_FILES[$field]['name'][$index],
            'type' => $_FILES[$field]['type'][$index] ?? '',
            'tmp_name' => $_FILES[$field]['tmp_name'][$index],
            'error' => $_FILES[$field]['error'][$index],
            'size' => $_FILES[$field]['size'][$index],
        ];
        $path = upload_file('__batch_upload', $allowed, $folder);
        if ($path) $uploaded[] = $path;
    }
    unset($_FILES['__batch_upload']);
    return $uploaded;
}
function ensure_event_images_table(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS event_images (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id INT UNSIGNED NOT NULL,
        image_path VARCHAR(255) NOT NULL,
        sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_event_images_event (event_id, sort_order),
        CONSTRAINT fk_event_images_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function ensure_event_tags_column(): void {
    $column = db()->query("SHOW COLUMNS FROM events LIKE 'tags'")->fetch();
    if (!$column) db()->exec("ALTER TABLE events ADD COLUMN tags VARCHAR(500) NULL AFTER description");
}
function ensure_booking_schema(): void {
    $eventColumns = [
        'booking_enabled' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER registration_url",
        'pricing_type' => "ENUM('free','paid') NOT NULL DEFAULT 'free' AFTER booking_enabled",
        'member_price' => "DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER pricing_type",
        'non_member_price' => "DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER member_price",
        'currency' => "CHAR(3) NOT NULL DEFAULT 'LKR' AFTER non_member_price",
        'capacity' => "INT UNSIGNED NULL AFTER currency",
        'bank_transfer_enabled' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER capacity",
        'bank_details' => "TEXT NULL AFTER bank_transfer_enabled",
    ];
    foreach ($eventColumns as $name => $definition) {
        if (!db()->query("SHOW COLUMNS FROM events LIKE " . db()->quote($name))->fetch()) {
            db()->exec("ALTER TABLE events ADD COLUMN $name $definition");
        }
    }
    db()->exec("CREATE TABLE IF NOT EXISTS event_bookings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        reference VARCHAR(24) NOT NULL UNIQUE,
        event_id INT UNSIGNED NOT NULL,
        purchaser_name VARCHAR(160) NOT NULL,
        purchaser_email VARCHAR(190) NOT NULL,
        purchaser_phone VARCHAR(40) NOT NULL,
        organisation VARCHAR(190) NULL,
        designation VARCHAR(160) NULL,
        seats SMALLINT UNSIGNED NOT NULL,
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
        currency CHAR(3) NOT NULL DEFAULT 'LKR',
        payment_method ENUM('free','bank_transfer') NOT NULL,
        receipt_path VARCHAR(255) NULL,
        status ENUM('pending_receipt','pending_verification','confirmed','rejected','cancelled') NOT NULL,
        admin_notes TEXT NULL,
        source_ip_hash CHAR(64) NULL,
        verified_by INT UNSIGNED NULL,
        verified_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_booking_event (event_id, status),
        INDEX idx_booking_status (status, created_at),
        CONSTRAINT fk_booking_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
        CONSTRAINT fk_booking_admin FOREIGN KEY (verified_by) REFERENCES admins(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS event_booking_participants (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        booking_id INT UNSIGNED NOT NULL,
        name VARCHAR(160) NOT NULL,
        email VARCHAR(190) NOT NULL,
        phone VARCHAR(40) NULL,
        organisation VARCHAR(190) NULL,
        designation VARCHAR(160) NULL,
        attendee_type ENUM('member','non_member') NOT NULL DEFAULT 'non_member',
        membership_number VARCHAR(80) NULL,
        nic VARCHAR(24) NULL,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_participant_booking (booking_id),
        CONSTRAINT fk_participant_booking FOREIGN KEY (booking_id) REFERENCES event_bookings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!table_has_column('event_booking_participants', 'nic')) {
        db()->exec("ALTER TABLE event_booking_participants ADD COLUMN nic VARCHAR(24) NULL AFTER membership_number");
    }
    db()->exec("CREATE TABLE IF NOT EXISTS event_tickets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ticket_number VARCHAR(40) NOT NULL UNIQUE,
        token CHAR(64) NOT NULL UNIQUE,
        booking_id INT UNSIGNED NOT NULL,
        participant_id INT UNSIGNED NOT NULL UNIQUE,
        status ENUM('issued','checked_in','void') NOT NULL DEFAULT 'issued',
        checked_in_at DATETIME NULL,
        checked_in_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ticket_booking (booking_id),
        INDEX idx_ticket_status (status, checked_in_at),
        CONSTRAINT fk_ticket_booking FOREIGN KEY (booking_id) REFERENCES event_bookings(id) ON DELETE CASCADE,
        CONSTRAINT fk_ticket_participant FOREIGN KEY (participant_id) REFERENCES event_booking_participants(id) ON DELETE CASCADE,
        CONSTRAINT fk_ticket_admin FOREIGN KEY (checked_in_by) REFERENCES admins(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function database_has_table(string $table): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    $stmt = db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}
function table_has_column(string $table, string $column): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) return false;
    $stmt = db()->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}
function event_images(int $eventId): array {
    ensure_event_images_table();
    $stmt = db()->prepare('SELECT id,image_path,sort_order FROM event_images WHERE event_id=? ORDER BY sort_order,id');
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}
function public_url(?string $path): ?string {
    global $config;
    if (!$path) return null;
    if (preg_match('#^uploads/images/([a-f0-9]{32})\.(?:jpg|png|webp)$#i', ltrim($path, '/'), $match)) {
        return rtrim($config['app_url'], '/') . '/media.php?file=' . rawurlencode(strtolower($match[1]) . '.webp');
    }
    return rtrim($config['app_url'], '/') . '/' . ltrim($path, '/');
}

function ticket_url(string $token): string {
    global $config;
    return rtrim((string)$config['app_url'], '/') . '/ticket.php?token=' . rawurlencode($token);
}
function ticket_validation_url(string $token): string {
    global $config;
    return rtrim((string)$config['app_url'], '/') . '/validate.php?token=' . rawurlencode($token);
}
function issue_tickets_for_booking(int $bookingId): array {
    ensure_booking_schema();
    $bookingStmt = db()->prepare("SELECT id,status FROM event_bookings WHERE id=? LIMIT 1");
    $bookingStmt->execute([$bookingId]);
    $booking = $bookingStmt->fetch();
    if (!$booking || $booking['status'] !== 'confirmed') return [];

    $participantStmt = db()->prepare('SELECT id FROM event_booking_participants WHERE booking_id=? ORDER BY id');
    $participantStmt->execute([$bookingId]);
    $participants = $participantStmt->fetchAll();
    $existingStmt = db()->prepare('SELECT * FROM event_tickets WHERE participant_id=? LIMIT 1');
    $insertStmt = db()->prepare("INSERT INTO event_tickets(ticket_number,token,booking_id,participant_id,status) VALUES(?,?,?,?,'issued')");
    $restoreStmt = db()->prepare("UPDATE event_tickets SET status='issued',checked_in_at=NULL,checked_in_by=NULL WHERE id=? AND status='void'");
    $tickets = [];
    foreach ($participants as $participant) {
        $existingStmt->execute([(int)$participant['id']]);
        $ticket = $existingStmt->fetch();
        if ($ticket) {
            $restoreStmt->execute([(int)$ticket['id']]);
        } else {
            do {
                $number = 'SLIID-' . date('y') . '-T' . strtoupper(bin2hex(random_bytes(4)));
                $check = db()->prepare('SELECT 1 FROM event_tickets WHERE ticket_number=?');
                $check->execute([$number]);
            } while ($check->fetchColumn());
            $token = bin2hex(random_bytes(32));
            $insertStmt->execute([$number,$token,$bookingId,(int)$participant['id']]);
        }
    }
    $ticketStmt = db()->prepare('SELECT t.*,p.name,p.email,p.attendee_type,p.membership_number,p.nic FROM event_tickets t JOIN event_booking_participants p ON p.id=t.participant_id WHERE t.booking_id=? ORDER BY p.id');
    $ticketStmt->execute([$bookingId]);
    return $ticketStmt->fetchAll();
}
function send_ticket_email(int $bookingId): bool {
    global $config;
    $stmt = db()->prepare('SELECT b.reference,b.purchaser_name,b.purchaser_email,e.title,e.starts_at,e.venue FROM event_bookings b JOIN events e ON e.id=b.event_id WHERE b.id=? AND b.status=\'confirmed\' LIMIT 1');
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    if (!$booking || !filter_var($booking['purchaser_email'], FILTER_VALIDATE_EMAIL)) return false;
    $tickets = issue_tickets_for_booking($bookingId);
    if (!$tickets) return false;
    $host = parse_url((string)($config['app_url'] ?? ''), PHP_URL_HOST) ?: 'sliid.org';
    $host = preg_replace('/^www\./i', '', (string)$host);
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: SLIID Events <no-reply@' . $host . '>',
        'Reply-To: info@' . $host,
    ];
    $eventDate = date('l, F j, Y · g:i A', strtotime($booking['starts_at']));
    $mailHeaders = implode("\r\n", $headers);
    $allSent = true;
    $participantEmails = [];
    $links = '';
    foreach ($tickets as $ticket) {
        $type = $ticket['attendee_type'] === 'member' ? 'SLIID member' : 'Non-member';
        $button = '<a href="' . e(ticket_url($ticket['token'])) . '" style="background:#ac874b;color:#fff;text-decoration:none;padding:12px 18px;display:inline-block">Open your e-ticket</a>';
        $personalBody = '<!doctype html><html><body style="margin:0;background:#f4f1eb;font-family:Arial,sans-serif;color:#172733"><div style="max-width:640px;margin:0 auto;padding:28px 16px"><div style="background:#071c29;color:#fff;padding:26px"><div style="font-family:Georgia,serif;font-size:36px">SLIID</div><div style="color:#d6bd85;text-transform:uppercase;letter-spacing:2px;font-size:11px">Official event e-ticket</div></div><div style="background:#fff;padding:26px"><p>Dear ' . e($ticket['name']) . ',</p><p>Your ticket for <strong>' . e($booking['title']) . '</strong> is confirmed.</p><p><strong>' . e($eventDate) . '</strong><br>' . e($booking['venue'] ?: 'Venue to be confirmed') . '</p><p>' . $button . '</p><p style="color:#66737a;font-size:13px">Present the QR code on your phone or print the ticket. Ticket number: <strong>' . e($ticket['ticket_number']) . '</strong></p></div></div></body></html>';
        $recipient = strtolower((string)$ticket['email']);
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $participantEmails[] = $recipient;
            $allSent = @mail($recipient, 'Your SLIID e-ticket - ' . $booking['title'], $personalBody, $mailHeaders) && $allSent;
        } else $allSent = false;
        $links .= '<tr><td style="padding:14px;border-bottom:1px solid #e6e0d5"><strong>' . e($ticket['name']) . '</strong><br><span style="color:#66737a">' . e($type) . ' · ' . e($ticket['ticket_number']) . '</span></td><td style="padding:14px;border-bottom:1px solid #e6e0d5;text-align:right"><a href="' . e(ticket_url($ticket['token'])) . '" style="background:#ac874b;color:#fff;text-decoration:none;padding:10px 14px;display:inline-block">Open e-ticket</a></td></tr>';
    }
    $purchaserEmail = strtolower((string)$booking['purchaser_email']);
    if (count($tickets) > 1 || !in_array($purchaserEmail, $participantEmails, true)) {
        $summaryBody = '<!doctype html><html><body style="margin:0;background:#f4f1eb;font-family:Arial,sans-serif;color:#172733"><div style="max-width:680px;margin:0 auto;padding:28px 16px"><div style="background:#071c29;color:#fff;padding:26px"><div style="font-family:Georgia,serif;font-size:36px">SLIID</div><div style="color:#d6bd85;text-transform:uppercase;letter-spacing:2px;font-size:11px">Booking confirmed</div></div><div style="background:#fff;padding:26px"><p>Dear ' . e($booking['purchaser_name']) . ',</p><p>All tickets for <strong>' . e($booking['title']) . '</strong> are ready.</p><p><strong>' . e($eventDate) . '</strong><br>' . e($booking['venue'] ?: 'Venue to be confirmed') . '</p><table style="width:100%;border-collapse:collapse">' . $links . '</table><p style="color:#66737a;font-size:13px;margin-top:24px">Each attendee has also been sent their individual ticket.</p><p style="font-size:12px;color:#879198">Booking reference: ' . e($booking['reference']) . '</p></div></div></body></html>';
        $allSent = @mail($purchaserEmail, 'Your SLIID booking is confirmed - ' . $booking['title'], $summaryBody, $mailHeaders) && $allSent;
    }
    return $allSent;
}
