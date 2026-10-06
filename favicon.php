<?php
declare(strict_types=1);

// Serve the current institution crest as a true circular PNG favicon. The
// source still falls back to CACSA's existing crest if a branding record or a
// future uploaded image is temporarily unavailable.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'mysql_data_store.php';

$logoPath = 'CACSA Logo.jpeg';
try {
    if (mysqlStorageEnabled()) {
        $branding = mysqlInstitutionBranding();
        if (is_array($branding) && !empty($branding['logoPath'])) $logoPath = rawurldecode((string)$branding['logoPath']);
    }
} catch (Throwable) {
    // A favicon must never make the portal fail to load; use the safe default.
}
$candidate = realpath(__DIR__ . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $logoPath), DIRECTORY_SEPARATOR));
$root = realpath(__DIR__);
if ($candidate === false || $root === false || !str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) || !is_file($candidate)) $candidate = __DIR__ . DIRECTORY_SEPARATOR . 'CACSA Logo.jpeg';
$extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
$source = match ($extension) {
    'jpg', 'jpeg' => @imagecreatefromjpeg($candidate),
    'png' => @imagecreatefrompng($candidate),
    'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($candidate) : false,
    default => false,
};

if ($source === false) {
    http_response_code(404);
    exit;
}

$size = 96;
$icon = imagecreatetruecolor($size, $size);
imagealphablending($icon, false);
imagesavealpha($icon, true);
$transparent = imagecolorallocatealpha($icon, 0, 0, 0, 127);
imagefill($icon, 0, 0, $transparent);

$sourceWidth = imagesx($source);
$sourceHeight = imagesy($source);
imagecopyresampled($icon, $source, 0, 0, 0, 0, $size, $size, $sourceWidth, $sourceHeight);

$center = ($size - 1) / 2;
$radius = $center;
for ($y = 0; $y < $size; $y++) {
    for ($x = 0; $x < $size; $x++) {
        $distanceSquared = ($x - $center) ** 2 + ($y - $center) ** 2;
        if ($distanceSquared > $radius ** 2) {
            imagesetpixel($icon, $x, $y, $transparent);
        }
    }
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');
imagepng($icon);
imagedestroy($source);
imagedestroy($icon);
