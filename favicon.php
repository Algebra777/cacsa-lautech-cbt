<?php
declare(strict_types=1);

// Serve the CACSA crest as a true circular PNG favicon. The source logo is a
// square JPEG, so a generated transparent PNG is more reliable in browser UI
// than asking every browser to clip an external SVG image reference.
$sourcePath = __DIR__ . DIRECTORY_SEPARATOR . 'CACSA Logo.jpeg';
$source = is_file($sourcePath) ? @imagecreatefromjpeg($sourcePath) : false;

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
header('Cache-Control: public, max-age=86400');
imagepng($icon);
imagedestroy($source);
imagedestroy($icon);
