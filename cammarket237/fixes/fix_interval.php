<?php
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);

// Fix the INTERVAL ' hours' bug - needs hours value
$old = "AND l.created_at > NOW() - INTERVAL ' hours'";
$new = "AND l.created_at > NOW() - INTERVAL '\" . \$hours . \" hours'";

$count = substr_count($c, $old);
echo "Found $count occurrences of bad INTERVAL\n";

if ($count > 0) {
    $c = str_replace($old, $new, $c);
    file_put_contents($f, $c);
    echo "Fixed!\n";
}

echo shell_exec('php -l ' . $f);
echo "Size: " . filesize($f) . "\n";
