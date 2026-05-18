<?php
// Fix admin.php DB connection for both Docker (host=db) and production (host=localhost)
$f = '/var/www/cammarket237/admin.php';
$c = file_get_contents($f);

// Fix DB connection to auto-detect environment
$old = "define('DB_DSN',  'pgsql:host=localhost;dbname=cammarket237_db');";
$new = "define('DB_DSN',  'pgsql:host=' . (file_exists('/.dockerenv') ? 'db' : 'localhost') . ';dbname=cammarket237_db');";

if (strpos($c, $old) !== false) {
    $c = str_replace($old, $new, $c);
    file_put_contents($f, $c);
    echo "Admin panel DB fixed!\n";
    echo shell_exec('php -l ' . $f . ' 2>&1');
} else {
    // Try host=db version
    $old2 = "define('DB_DSN',  'pgsql:host=db;dbname=cammarket237_db');";
    if (strpos($c, $old2) !== false) {
        $c = str_replace($old2, $new, $c);
        file_put_contents($f, $c);
        echo "Admin panel DB fixed (from db)!\n";
        echo shell_exec('php -l ' . $f . ' 2>&1');
    } else {
        echo "Pattern not found\n";
        echo substr($c, 0, 200) . "\n";
    }
}
