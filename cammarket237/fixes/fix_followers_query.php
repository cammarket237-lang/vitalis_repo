<?php
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);

$old = '(SELECT COUNT(*) FROM cammarket237.followers f WHERE f.store_id=san.store_id) AS follower_count,';
$new = '(SELECT COUNT(*) FROM cammarket237.followers f JOIN cammarket237.stores s2 ON s2.user_id=f.following_id WHERE s2.id=san.store_id) AS follower_count,';

if (strpos($c, $old) !== false) {
    $c = str_replace($old, $new, $c);
    file_put_contents($f, $c);
    echo "Fixed!\n";
    echo shell_exec('php -l ' . $f . ' 2>&1');
} else {
    echo "Pattern not found\n";
}
