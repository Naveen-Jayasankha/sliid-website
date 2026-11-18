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
function upload_file(string $field, array $allowed, string $folder): ?string {
    global $config;
    if (empty($_FILES[$field]['name'])) return null;
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > ($config['upload_max_bytes'] ?? 5242880)) throw new RuntimeException('Invalid or oversized upload.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowed[$mime])) throw new RuntimeException('Unsupported file type.');
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $dir = __DIR__ . '/uploads/' . trim($folder, '/');
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Unable to create upload directory.');
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Upload failed.');
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
function event_images(int $eventId): array {
    ensure_event_images_table();
    $stmt = db()->prepare('SELECT id,image_path,sort_order FROM event_images WHERE event_id=? ORDER BY sort_order,id');
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}
function public_url(?string $path): ?string {
    global $config;
    if (!$path) return null;
    return rtrim($config['app_url'], '/') . '/' . ltrim($path, '/');
}
