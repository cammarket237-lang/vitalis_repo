<?php
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);
$c = str_replace(
    "if (\$action === 'admin_add_minutes')",
    "if (!defined('ADMIN_PASS')) define('ADMIN_PASS', 'REMOVED-6273c5c8b3');\nif (\$action === 'admin_add_minutes')",
    $c
);
file_put_contents($f, $c);
echo "Fixed!\n";
