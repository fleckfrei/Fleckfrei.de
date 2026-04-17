<?php
// Dynamic PNG icon generator — renders brand letter on brand color
$size = max(16, min(1024, (int)($_GET['s'] ?? 192)));
$img = imagecreatetruecolor($size, $size);

// Brand color <?= BRAND ?>
$bg = imagecolorallocate($img, 46, 125, 107);
$white = imagecolorallocate($img, 255, 255, 255);
imagefill($img, 0, 0, $bg);

// Rounded corners (approximate with filled arcs)
$radius = (int)($size * 0.15);
imagefilledrectangle($img, $radius, 0, $size - $radius, $size, $bg);
imagefilledrectangle($img, 0, $radius, $size, $size - $radius, $bg);
imagefilledarc($img, $radius, $radius, $radius * 2, $radius * 2, 180, 270, $bg, IMG_ARC_PIE);
imagefilledarc($img, $size - $radius, $radius, $radius * 2, $radius * 2, 270, 360, $bg, IMG_ARC_PIE);
imagefilledarc($img, $radius, $size - $radius, $radius * 2, $radius * 2, 90, 180, $bg, IMG_ARC_PIE);
imagefilledarc($img, $size - $radius, $size - $radius, $radius * 2, $radius * 2, 0, 90, $bg, IMG_ARC_PIE);

// Letter "F" centered
$fontSize = (int)($size * 0.5);
$font = __DIR__ . '/../fonts/Inter-Bold.ttf';
if (!file_exists($font)) {
    // Fallback: use built-in font
    $builtinSize = (int)($size / 4);
    $x = (int)($size * 0.38);
    $y = (int)($size * 0.62);
    imagestring($img, 5, $x, (int)($size * 0.35), 'F', $white);
} else {
    $bbox = imagettfbbox($fontSize, 0, $font, 'F');
    $x = (int)(($size - ($bbox[2] - $bbox[0])) / 2);
    $y = (int)(($size - ($bbox[1] - $bbox[7])) / 2 + ($bbox[1] - $bbox[7]));
    imagettftext($img, $fontSize, 0, $x, $y, $white, $font, 'F');
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000');
imagepng($img);
imagedestroy($img);
