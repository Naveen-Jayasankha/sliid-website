<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$name = basename((string) ($_GET['file'] ?? ''));
if (!preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/i', $name)) {
    http_response_code(404);
    exit;
}

$directory = realpath(__DIR__ . '/uploads/images');
$file = $directory ? realpath($directory . DIRECTORY_SEPARATOR . $name) : false;
if (!$directory || !$file || !str_starts_with($file, $directory . DIRECTORY_SEPARATOR) || !is_file($file) || !is_readable($file)) {
    http_response_code(404);
    exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
$allowed = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowed, true)) {
    http_response_code(415);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Cache-Control: public, max-age=604800, immutable');
header('X-Content-Type-Options: nosniff');
readfile($file);
