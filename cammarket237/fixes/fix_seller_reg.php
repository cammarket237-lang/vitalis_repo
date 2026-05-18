<?php
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);

$old = "    // Check phone not already registered AS SELLER (allow same phone as buyer)
    \$chk = db()->prepare(\"SELECT id FROM cammarket237.users WHERE phone=? AND role='seller' LIMIT 1\");
    \$chk->execute([\$phone]);
    if (\$chk->fetch()) fail('Phone already registered as seller. Please login.');";

$new = "    // Check phone not already registered AS SELLER (check all variants)
    \$phoneVariants = normalizePhone(\$phone);
    \$placeholders = implode(',', array_fill(0, count(\$phoneVariants), '?'));
    \$chkStmt = db()->prepare(\"SELECT id FROM cammarket237.users WHERE phone IN (\$placeholders) AND role='seller' LIMIT 1\");
    \$chkStmt->execute(\$phoneVariants);
    if (\$chkStmt->fetch()) fail('Phone already registered as seller. Please login.');";

if (strpos($c, $old) !== false) {
    $c = str_replace($old, $new, $c);
    file_put_contents($f, $c);
    echo "Seller reg fix applied!\n";
    echo shell_exec('php -l ' . $f . ' 2>&1');
} else {
    echo "Already fixed or pattern not found\n";
}
