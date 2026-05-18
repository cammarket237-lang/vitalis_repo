<?php
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);

$old = "    // Short number - try adding common country codes
    if (strlen(\$digits) <= 9) {
        \$variants[] = '237' . \$digits;   // Cameroon
        \$variants[] = '+237' . \$digits;
        \$variants[] = '1' . \$digits;     // USA
        \$variants[] = '+1' . \$digits;
    }

    return array_unique(\$variants);
}";

$new = "    // 10-digit US number (without leading 1)
    if (strlen(\$digits) === 10 && substr(\$digits, 0, 1) !== '0') {
        \$variants[] = '1' . \$digits;
        \$variants[] = '+1' . \$digits;
    }

    // Short number - try adding common country codes
    if (strlen(\$digits) <= 9) {
        \$variants[] = '237' . \$digits;   // Cameroon
        \$variants[] = '+237' . \$digits;
        \$variants[] = '1' . \$digits;     // USA
        \$variants[] = '+1' . \$digits;
    }

    // 9-digit Cameroon number (without 237)
    if (strlen(\$digits) === 9 && (substr(\$digits, 0, 1) === '6' || substr(\$digits, 0, 1) === '2')) {
        \$variants[] = '237' . \$digits;
        \$variants[] = '+237' . \$digits;
    }

    return array_unique(\$variants);
}";

if (strpos($c, $old) !== false) {
    $c = str_replace($old, $new, $c);
    file_put_contents($f, $c);
    echo "Phone normalization fixed!\n";
    echo shell_exec('php -l ' . $f . ' 2>&1');
} else {
    echo "Pattern not found\n";
}
