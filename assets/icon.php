<?php
/**
 * Generates the app icon as PNG at the requested size.
 * Used for apple-touch-icon and PWA manifest icons. Cached on disk.
 *
 *   /assets/icon.php?size=180
 *
 * Design: green rounded square + lowercase "rss" in etoile (if available)
 * with letter-spacing. Falls back to a system TTF if etoile isn't on disk.
 */
declare(strict_types=1);

$size = max(32, min(1024, (int)($_GET['size'] ?? 180)));
$cacheFile = __DIR__ . "/icon-cache-{$size}.png";

// ?debug=1 reports which font was found and where the cache file is
if (!empty($_GET['debug'])) {
    $font = find_icon_font();
    $glyphProbe = null;
    if ($font && function_exists('imagettfbbox')) {
        $bboxLower = imagettfbbox(60, 0, $font, 'r');
        $bboxUpper = imagettfbbox(60, 0, $font, 'R');
        $glyphProbe = [
            'lowercase_r_width' => $bboxLower ? $bboxLower[2] - $bboxLower[0] : 0,
            'uppercase_R_width' => $bboxUpper ? $bboxUpper[2] - $bboxUpper[0] : 0,
            'will_use'          => ($bboxLower && ($bboxLower[2] - $bboxLower[0]) >= 9) ? 'rss' : 'RSS',
        ];
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'requested_size' => $size,
        'cache_file'     => $cacheFile,
        'cache_exists'   => is_file($cacheFile),
        'gd_available'   => function_exists('imagecreatetruecolor'),
        'ttf_supported'  => function_exists('imagettftext'),
        'font_used'      => $font,
        'glyph_probe'    => $glyphProbe,
        'searched_paths' => debug_font_candidates(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// nocache=1 deletes the cached PNG before regeneration
if (!empty($_GET['nocache']) && is_file($cacheFile)) @unlink($cacheFile);

if (is_file($cacheFile)) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile($cacheFile);
    exit;
}

if (!function_exists('imagecreatetruecolor')) {
    header('Location: icon.svg');
    exit;
}

$im = imagecreatetruecolor($size, $size);
imagesavealpha($im, true);
imagealphablending($im, true);
$transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
imagefill($im, 0, 0, $transparent);

$green = imagecolorallocate($im, 0x17, 0x5d, 0x3b);
$white = imagecolorallocate($im, 0xf5, 0xf5, 0xf7);

// Rounded square background
$r = (int)round($size * 0.22);
imagefilledrectangle($im, $r, 0, $size - $r, $size, $green);
imagefilledrectangle($im, 0, $r, $size, $size - $r, $green);
imagefilledellipse($im, $r, $r, $r * 2, $r * 2, $green);
imagefilledellipse($im, $size - $r, $r, $r * 2, $r * 2, $green);
imagefilledellipse($im, $r, $size - $r, $r * 2, $r * 2, $green);
imagefilledellipse($im, $size - $r, $size - $r, $r * 2, $r * 2, $green);

$font = find_icon_font();
$letters = ['r', 's', 's'];
$tracking = 0.18; // letter-spacing factor relative to font size

if ($font && function_exists('imagettftext')) {
    // Compute font size such that the "rss" word fills ~62% of the icon width.
    $fontSize = (int)round($size * 0.55);

    // Detect whether the chosen font actually has glyphs for our letters.
    // Many display fonts (incl. some etoile cuts) only ship uppercase —
    // imagettfbbox returns a zero-width box for missing glyphs, which would
    // produce a blank icon. Fall back to uppercase if that's the case.
    $probe = imagettfbbox($fontSize, 0, $font, 'r');
    if (!$probe || ($probe[2] - $probe[0]) < $fontSize * 0.15) {
        $letters = ['R', 'S', 'S'];
    }

    [$widths, $totalW, $maxH] = measure_word($fontSize, $font, $letters, $tracking);

    // If the word doesn't fit horizontally, scale font down
    if ($totalW > $size * 0.78) {
        $fontSize = (int)round($fontSize * ($size * 0.78) / $totalW);
        [$widths, $totalW, $maxH] = measure_word($fontSize, $font, $letters, $tracking);
    }

    $gap   = (int)round($fontSize * $tracking);
    $startX = (int)round(($size - $totalW) / 2);
    $baselineY = (int)round(($size + $maxH) / 2);

    $x = $startX;
    foreach ($letters as $i => $l) {
        imagettftext($im, $fontSize, 0, $x, $baselineY, $white, $font, $l);
        $x += $widths[$i] + $gap;
    }
} else {
    // Last-resort fallback: built-in GD font, scaled by drawing onto a
    // small canvas and copyresampled. Not pretty but never blank.
    $tmp = imagecreatetruecolor(60, 14);
    imagefill($tmp, 0, 0, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
    imagesavealpha($tmp, true);
    $w2 = imagecolorallocate($tmp, 0xf5, 0xf5, 0xf7);
    imagestring($tmp, 5, 18, 0, 'rss', $w2);
    $target = (int)round($size * 0.7);
    imagecopyresampled($im, $tmp, (int)round(($size - $target) / 2), (int)round(($size - $target * 14 / 60) / 2),
                       0, 0, $target, (int)round($target * 14 / 60), 60, 14);
    imagedestroy($tmp);
}

@imagepng($im, $cacheFile, 9);
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
imagepng($im);
imagedestroy($im);

// ---- helpers ----

function debug_font_candidates(): array {
    return [
        __DIR__ . '/etoile_font/Etoile-Regular.ttf',
        __DIR__ . '/etoile_font/etoile.ttf',
        __DIR__ . '/etoile_font/Etoile.ttf',
        __DIR__ . '/Etoile-Regular.ttf',
        __DIR__ . '/etoile.ttf',
        __DIR__ . '/font/Etoile-Regular.ttf',
        __DIR__ . '/fonts/Etoile-Regular.ttf',
        __DIR__ . '/../etoile_font/Etoile-Regular.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
        '/usr/share/fonts/freefont/FreeSans.ttf',
        '/Library/Fonts/Arial.ttf',
        '/System/Library/Fonts/Helvetica.ttc',
    ];
}

function find_icon_font(): ?string {
    foreach (debug_font_candidates() as $p) {
        if (is_file($p) && is_readable($p)) return $p;
    }
    // Last attempt: glob for any *.ttf inside the etoile_font dir
    foreach (glob(__DIR__ . '/etoile_font/*.ttf') ?: [] as $hit) {
        if (is_readable($hit)) return $hit;
    }
    foreach (glob(__DIR__ . '/*.ttf') ?: [] as $hit) {
        if (is_readable($hit)) return $hit;
    }
    return null;
}

function measure_word(int $fontSize, string $font, array $letters, float $tracking): array {
    $widths = []; $maxH = 0; $total = 0;
    foreach ($letters as $l) {
        $bbox = imagettfbbox($fontSize, 0, $font, $l);
        $w = $bbox[2] - $bbox[0];
        $h = $bbox[1] - $bbox[7];
        $widths[] = $w;
        $total += $w;
        if ($h > $maxH) $maxH = $h;
    }
    $total += (int)round($fontSize * $tracking) * (count($letters) - 1);
    return [$widths, $total, $maxH];
}
