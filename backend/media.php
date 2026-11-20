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

// Older database records can still point at JPG or PNG files. When the public
// URL requests the matching WebP name, create and cache it on first use.
if ($directory && !$file && preg_match('/^([a-f0-9]{32})\.webp$/i', $name, $match)) {
    foreach (['jpg' => 'image/jpeg', 'png' => 'image/png'] as $extension => $sourceMime) {
        $source = realpath($directory . DIRECTORY_SEPARATOR . $match[1] . '.' . $extension);
        if ($source && str_starts_with($source, $directory . DIRECTORY_SEPARATOR) && is_readable($source)) {
            $target = $directory . DIRECTORY_SEPARATOR . strtolower($match[1]) . '.webp';
            if (save_image_as_webp($source, $sourceMime, $target)) {
                $file = realpath($target);
            } else {
                $file = $source;
            }
            break;
        }
    }
}
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
