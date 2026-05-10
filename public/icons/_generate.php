<?php
// Run from CLI to (re)generate PWA icons:
//   /Applications/MAMP/bin/php/php8.3.28/bin/php public/icons/_generate.php
declare(strict_types=1);

function makeIcon(int $size, string $outfile): void {
    $img = imagecreatetruecolor($size, $size);
    $bg  = imagecolorallocate($img, 7, 11, 20);     // #070B14 navy
    $red = imagecolorallocate($img, 229, 25, 55);   // #E51937 Delta red
    $redLow = imagecolorallocate($img, 138, 14, 33);
    imagefilledrectangle($img, 0, 0, $size, $size, $bg);

    // Delta-style stylized triangle (asymmetric, two tones)
    $cx = $size * 0.5;
    $cy = $size * 0.55;
    $w  = $size * 0.55;
    $h  = $size * 0.45;
    // Bright triangle (left/top)
    $left  = [(int)($cx - $w/2), (int)($cy + $h/2),
              (int)($cx),         (int)($cy - $h/2),
              (int)($cx),         (int)($cy + $h/2)];
    imagefilledpolygon($img, $left, $red);
    // Darker triangle (right)
    $right = [(int)($cx),         (int)($cy - $h/2),
              (int)($cx + $w/2),  (int)($cy + $h/2),
              (int)($cx),         (int)($cy + $h/2)];
    imagefilledpolygon($img, $right, $redLow);

    imagepng($img, $outfile);
    imagedestroy($img);
}

$dir = __DIR__;
makeIcon(192, "$dir/icon-192.png");
makeIcon(512, "$dir/icon-512.png");
makeIcon(180, "$dir/apple-touch-icon.png");
echo "Icons generated.\n";
