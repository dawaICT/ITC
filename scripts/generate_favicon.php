<?php
// Generate clean square favicons from the "itc" brand mark in the banner logo.
// Source logo (images/itc_logo.png) is actually a 657x117 JPEG banner, so it
// looks blurry/squished when a browser uses it directly as a tab favicon.
// This crops the bold red "itc" mark and emits sharp square icons.
// Run with: php -d extension=gd scripts/generate_favicon.php  (GD is not
// enabled in php.ini by default, so the -d flag is required on the CLI).
$src = imagecreatefromjpeg(__DIR__ . '/../images/itc_logo.png');
$W = imagesx($src); $H = imagesy($src);

// --- 1. Find the bounding box of the red "itc" mark (right half only) ---
$minX = $W; $minY = $H; $maxX = 0; $maxY = 0;
for ($y = 0; $y < $H; $y++) {
    for ($x = intval($W * 0.6); $x < $W; $x++) {
        $rgb = imagecolorat($src, $x, $y);
        $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
        if ($r > 150 && $g < 100 && $b < 100) { // red pixel
            if ($x < $minX) $minX = $x; if ($x > $maxX) $maxX = $x;
            if ($y < $minY) $minY = $y; if ($y > $maxY) $maxY = $y;
        }
    }
}
echo "red bbox: x[$minX..$maxX] y[$minY..$maxY]\n";

$pad = 4; // px padding around mark inside the crop
$minX = max(0, $minX - $pad); $minY = max(0, $minY - $pad);
$maxX = min($W - 1, $maxX + $pad); $maxY = min($H - 1, $maxY + $pad);
$cw = $maxX - $minX + 1; $ch = $maxY - $minY + 1;

// crop the mark
$mark = imagecreatetruecolor($cw, $ch);
$white = imagecolorallocate($mark, 255, 255, 255);
imagefill($mark, 0, 0, $white);
imagecopy($mark, $src, 0, 0, $minX, $minY, $cw, $ch);

// Clean up: whiten any stray greenish pixels (leftover "Centre" text fragment)
for ($y = 0; $y < $ch; $y++) {
    for ($x = 0; $x < $cw; $x++) {
        $rgb = imagecolorat($mark, $x, $y);
        $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
        if ($g > $r + 10 && $g > $b + 10) imagesetpixel($mark, $x, $y, $white);
    }
}
// Top-left corner is background for the slanted parallelogram: whiten any
// non-red pixel there to remove leftover text specks.
$clX = intval($cw * 0.14); $clY = intval($ch * 0.45);
for ($y = 0; $y < $clY; $y++) {
    for ($x = 0; $x < $clX; $x++) {
        $rgb = imagecolorat($mark, $x, $y);
        $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
        if (!($r > 150 && $g < 100 && $b < 100)) imagesetpixel($mark, $x, $y, $white);
    }
}

// --- 2. Build a square master with white bg + the mark centered, scaled to ~84% ---
$master = 256;
$canvas = imagecreatetruecolor($master, $master);
imagealphablending($canvas, true);
$bg = imagecolorallocate($canvas, 255, 255, 255);
imagefill($canvas, 0, 0, $bg);

$inner = intval($master * 0.84);
$scale = min($inner / $cw, $inner / $ch);
$dw = intval($cw * $scale); $dh = intval($ch * $scale);
$dx = intval(($master - $dw) / 2); $dy = intval(($master - $dh) / 2);
imagecopyresampled($canvas, $mark, $dx, $dy, 0, 0, $dw, $dh, $cw, $ch);

// --- 3. Emit PNG sizes ---
$imgDir = __DIR__ . '/../images';
$assetDir = __DIR__ . '/../assets/img';
function emitPng($canvas, $size, $path) {
    $im = imagecreatetruecolor($size, $size);
    $w = imagecolorallocate($im, 255, 255, 255); imagefill($im, 0, 0, $w);
    imagecopyresampled($im, $canvas, 0, 0, 0, 0, $size, $size, imagesx($canvas), imagesy($canvas));
    imagepng($im, $path);
    return $im;
}
emitPng($canvas, 256, "$imgDir/favicon.png");          // generic / hi-dpi
emitPng($canvas, 180, "$imgDir/apple-touch-icon.png"); // iOS
$im48 = emitPng($canvas, 48, "$imgDir/favicon-48.png");
$im32 = emitPng($canvas, 32, "$imgDir/favicon-32.png");
$im16 = emitPng($canvas, 16, "$imgDir/favicon-16.png");

// --- 4. Build a multi-size .ico (PNG-compressed entries: 16,32,48) ---
function pngData($im) { ob_start(); imagepng($im); return ob_get_clean(); }
$entries = [
    [16, pngData($im16)],
    [32, pngData($im32)],
    [48, pngData($im48)],
];
$count = count($entries);
$ico = pack('vvv', 0, 1, $count); // reserved, type=1(icon), count
$offset = 6 + 16 * $count;
$blobs = '';
foreach ($entries as $e) {
    list($sz, $data) = $e;
    $len = strlen($data);
    $bw = ($sz >= 256) ? 0 : $sz;
    $ico .= pack('CCCCvvVV', $bw, $bw, 0, 0, 1, 32, $len, $offset);
    $offset += $len;
    $blobs .= $data;
}
$ico .= $blobs;
file_put_contents("$assetDir/favicon.ico", $ico);
file_put_contents("$imgDir/favicon.ico", $ico);

echo "Generated: images/favicon.png, apple-touch-icon.png, favicon-48/32/16.png, favicon.ico (+assets/img/favicon.ico)\n";
echo "ico bytes: " . strlen($ico) . "\n";
