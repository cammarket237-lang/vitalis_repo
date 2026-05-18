<?php
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);

$old = 'function normalizePhone($phone) {';

$new = 'function normalizePhone($phone) {
    // Pre-clean: decode URL encoding, trim spaces
    $phone = urldecode(trim($phone));
    // Replace spaces (from + in form POST) at start
    $phone = ltrim($phone);';

// Only add pre-clean if not already there
if (strpos($c, 'Pre-clean: decode URL') === false) {
    $c = str_replace($old, $new, $c);
    echo "Pre-clean added!\n";
} else {
    echo "Pre-clean already exists\n";
}

// Now replace full function with comprehensive version
$funcStart = strpos($c, 'function normalizePhone(');
$funcEnd = strpos($c, "\nfunction ", $funcStart + 10);
$currentFunc = substr($c, $funcStart, $funcEnd - $funcStart);

$newFunc = 'function normalizePhone($phone) {
    // Pre-clean: handle +, spaces, URL encoding
    $phone = urldecode(trim($phone));
    
    // Strip ALL non-digit characters to get raw digits
    $digits = preg_replace(\'/[^0-9]/\', \'\', $phone);
    
    if (!$digits) return [];

    $variants = [];

    // Always include raw digits and + version
    $variants[] = $digits;
    $variants[] = \'+\' . $digits;

    // ── CAMEROON (237) ──────────────────────────────────
    if (strlen($digits) >= 10 && substr($digits, 0, 3) === \'237\') {
        $local = substr($digits, 3);
        $variants[] = $local;
        $variants[] = \'+237\' . $local;
        $variants[] = \'237\' . $local;
    }
    // 9-digit Cameroon (6XXXXXXXX or 2XXXXXXXX)
    if (strlen($digits) === 9) {
        $variants[] = \'237\' . $digits;
        $variants[] = \'+237\' . $digits;
    }
    // 8-digit Cameroon
    if (strlen($digits) === 8) {
        $variants[] = \'237\' . $digits;
        $variants[] = \'+237\' . $digits;
    }

    // ── USA/CANADA (1) ──────────────────────────────────
    // 10-digit US (2408388119)
    if (strlen($digits) === 10 && substr($digits, 0, 1) !== \'2\' && substr($digits, 0, 3) !== \'237\') {
        $variants[] = \'1\' . $digits;
        $variants[] = \'+1\' . $digits;
    }
    // 10-digit starting with any digit - also try as US
    if (strlen($digits) === 10) {
        $variants[] = \'1\' . $digits;
        $variants[] = \'+1\' . $digits;
    }
    // 11-digit US with leading 1 (12408388119)
    if (strlen($digits) === 11 && substr($digits, 0, 1) === \'1\') {
        $local = substr($digits, 1);
        $variants[] = $local;
        $variants[] = \'+1\' . $local;
        $variants[] = \'1\' . $local;
    }

    // ── NIGERIA (234) ────────────────────────────────────
    if (strlen($digits) >= 10 && substr($digits, 0, 3) === \'234\') {
        $local = substr($digits, 3);
        $variants[] = $local;
        $variants[] = \'+234\' . $local;
    }

    // ── GHANA (233) ──────────────────────────────────────
    if (strlen($digits) >= 10 && substr($digits, 0, 3) === \'233\') {
        $local = substr($digits, 3);
        $variants[] = $local;
        $variants[] = \'+233\' . $local;
    }

    // Short fallback
    if (strlen($digits) <= 7) {
        $variants[] = \'237\' . $digits;
        $variants[] = \'+237\' . $digits;
        $variants[] = \'1\' . $digits;
    }

    return array_unique(array_filter($variants));
}

';

$c = substr_replace($c, $newFunc, $funcStart, strlen($currentFunc));
file_put_contents($f, $c);

$out = shell_exec('php -l ' . $f . ' 2>&1');
echo $out;
echo "Size: " . filesize($f) . "\n";

// Quick test of variants
echo "\nTest +12408388119:\n";
$phone = "+12408388119";
$digits = preg_replace('/[^0-9]/', '', $phone);
echo "digits=$digits len=" . strlen($digits) . "\n";
if (strlen($digits) === 11 && substr($digits, 0, 1) === '1') {
    echo "Variant: " . substr($digits, 1) . " (matches DB 2408388119)\n";
}

echo "\nTest +237674218700:\n";
$phone2 = "+237674218700";
$digits2 = preg_replace('/[^0-9]/', '', $phone2);
echo "digits=$digits2 len=" . strlen($digits2) . "\n";
if (strlen($digits2) >= 10 && substr($digits2, 0, 3) === '237') {
    echo "Variant: " . substr($digits2, 3) . "\n";
}
