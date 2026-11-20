<?php
declare(strict_types=1);

// Public images do not need a database connection or PHP session. Keeping
// this endpoint standalone avoids cookie/session headers on gallery requests.
function media_save_webp(string $source, string $mime, string $destination): bool {
    if (!function_exists('imagewebp')) return false;
    $image = match ($mime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($source) : false,
        'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($source) : false,
        default => false,
    };
    if (!$image) return false;
    if ($mime === 'image/png') {
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
    }
    $saved = @imagewebp($image, $destination, 82);
    imagedestroy($image);
    if (!$saved || !is_file($destination) || filesize($destination) < 1) {
        @unlink($destination);
        return false;
    }
    @chmod($destination, 0644);
    return true;
}

$name = basename((string) ($_GET['file'] ?? ''));
if (!preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/i', $name)) {
    http_response_code(404);
    exit;
}

$directory = realpath(__DIR__ . '/uploads/images');
$file = $directory ? realpath($directory . DIRECTORY_SEPARATOR . $name) : false;

// Convert an existing JPG/PNG to WebP once, then reuse the cached WebP file.
if ($directory && !$file && preg_match('/^([a-f0-9]{32})\.webp$/i', $name, $match)) {
    foreach (['jpg' => 'image/jpeg', 'png' => 'image/png'] as $extension => $sourceMime) {
        $source = realpath($directory . DIRECTORY_SEPARATOR . strtolower($match[1]) . '.' . $extension);
        if ($source && str_starts_with($source, $directory . DIRECTORY_SEPARATOR) && is_readable($source)) {
            $target = $directory . DIRECTORY_SEPARATOR . strtolower($match[1]) . '.webp';
            $file = media_save_webp($source, $sourceMime, $target) ? realpath($target) : $source;
            break;
        }
    }
}

if (!$directory || !$file || !str_starts_with($file, $directory . DIRECTORY_SEPARATOR) || !is_file($file) || !is_readable($file)) {
    http_response_code(404);
    exit;
}

$mime = function_exists('mime_content_type') ? @mime_content_type($file) : false;
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$extension] ?? null;
}
if (!$mime) {
    http_response_code(415);
    exit;
}

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=604800, immutable');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . basename($file) . '"');

$stream = @fopen($file, 'rb');
if (!$stream) {
    http_response_code(404);
    exit;
}
fpassthru($stream);
fclose($stream);
