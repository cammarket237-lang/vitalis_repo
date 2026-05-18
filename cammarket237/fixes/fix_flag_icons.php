<?php
// Cameroon flag icon WITH "CamMarket237" text below
$sizes = [72, 96, 128, 192, 512];
$outDir = '/var/www/cammarket237/';

foreach ($sizes as $size) {
    $img = imagecreatetruecolor($size, $size);

    $green   = imagecolorallocate($img, 0x00, 0x7A, 0x5E);
    $red     = imagecolorallocate($img, 0xCE, 0x11, 0x26);
    $yellow  = imagecolorallocate($img, 0xFC, 0xD1, 0x16);
    $white   = imagecolorallocate($img, 255, 255, 255);
    $darkBg  = imagecolorallocate($img, 0x0F, 0x19, 0x23);

    // Flag area = top 72% of icon, text area = bottom 28%
    $flagHeight = (int)($size * 0.72);
    $textAreaY  = $flagHeight;
    $textHeight = $size - $flagHeight;

    // 3 vertical stripes
    $third = $size / 3;
    imagefilledrectangle($img, 0,          0, $third,     $flagHeight, $green);
    imagefilledrectangle($img, $third,     0, $third * 2, $flagHeight, $red);
    imagefilledrectangle($img, $third * 2, 0, $size,      $flagHeight, $yellow);

    // Yellow star on red stripe
    $centerX = $size / 2;
    $centerY = $flagHeight / 2;
    $starRadius = $flagHeight * 0.25;

    $points = [];
    for ($i = 0; $i < 10; $i++) {
        $angle = ($i * 36 - 90) * M_PI / 180;
        $r = ($i % 2 == 0) ? $starRadius : $starRadius * 0.4;
        $points[] = $centerX + $r * cos($angle);
        $points[] = $centerY + $r * sin($angle);
    }
    imagefilledpolygon($img, $points, 10, $yellow);

    // Dark background bar for text
    imagefilledrectangle($img, 0, $textAreaY, $size, $size, $darkBg);

    // Yellow accent line at top of text bar (larger sizes only)
    if ($size >= 192) {
        imagefilledrectangle($img, $size * 0.2, $textAreaY + 2, $size * 0.8, $textAreaY + 4, $yellow);
    }

    // "CamMarket237" text
    $text = "CamMarket237";
    if ($size >= 256) $font = 5;
    elseif ($size >= 128) $font = 4;
    elseif ($size >= 96) $font = 3;
    else $font = 2;

    $textWidth = imagefontwidth($font) * strlen($text);
    if ($textWidth > $size - 8) {
        $font = max(2, $font - 1);
        $textWidth = imagefontwidth($font) * strlen($text);
    }
    $tx = ($size - $textWidth) / 2;
    $ty = $textAreaY + (($textHeight - imagefontheight($font)) / 2);
    imagestring($img, $font, $tx, $ty, $text, $white);

    // Backup old + save new
    $path = $outDir . "icon-$size.png";
    if (file_exists($path)) copy($path, $path . '.bak');
    imagepng($img, $path);
    imagedestroy($img);
    echo "Generated $path (" . filesize($path) . " bytes)\n";
}
echo "\nAll Cameroon flag icons with CamMarket237 name deployed!\n";
