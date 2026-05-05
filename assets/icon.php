<?php
/**
 * Generates the app icon as PNG at the requested size.
 * Used for apple-touch-icon and PWA manifest icons. Cached on disk.
 *
 *   /assets/icon.php?size=180
 */
declare(strict_types=1);

$size = max(32, min(1024, (int)($_GET['size'] ?? 180)));
$cacheFile = __DIR__ . "/icon-cache-{$size}.png";

if (is_file($cacheFile)) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($cacheFile);
    exit;
}

if (!function_exists('imagecreatetruecolor')) {
    // GD missing — fall back to redirecting to the SVG
    header('Location: icon.svg');
    exit;
}

$im = imagecreatetruecolor($size, $size);
imagesavealpha($im, true);
$transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
imagefill($im, 0, 0, $transparent);

$green  = imagecolorallocate($im, 0x17, 0x5d, 0x3b);
$accent = imagecolorallocate($im, 0xf4, 0xb8, 0x86);

// Rounded square
$r = (int)round($size * 0.22);
imagefilledrectangle($im, $r, 0, $size - $r, $size, $green);
imagefilledrectangle($im, 0, $r, $size, $size - $r, $green);
imagefilledellipse($im, $r, $r, $r * 2, $r * 2, $green);
imagefilledellipse($im, $size - $r, $r, $r * 2, $r * 2, $green);
imagefilledellipse($im, $r, $size - $r, $r * 2, $r * 2, $green);
imagefilledellipse($im, $size - $r, $size - $r, $r * 2, $r * 2, $green);

// RSS curls: a dot + two arcs in the lower-left
imagesetthickness($im, max(2, (int)round($size * 0.06)));
$cx = (int)round($size * 0.30);
$cy = (int)round($size * 0.74);
$dotR = (int)round($size * 0.06);
imagefilledellipse($im, $cx, $cy, $dotR * 2, $dotR * 2, $accent);

$arcThickness = max(2, (int)round($size * 0.06));
imagesetthickness($im, $arcThickness);
// inner arc
$inner = (int)round($size * 0.36);
imagearc($im, $cx, $cy, $inner * 2, $inner * 2, 270, 360, $accent);
// outer arc
$outer = (int)round($size * 0.56);
imagearc($im, $cx, $cy, $outer * 2, $outer * 2, 270, 360, $accent);

@imagepng($im, $cacheFile, 9);
header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000, immutable');
imagepng($im);
imagedestroy($im);
