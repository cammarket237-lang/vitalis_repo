<?php
// Fix remaining l.region references in get_new_items and similar queries
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);
$before = strlen($c);

// Fix all l.region patterns
$replacements = [
    'l.id, l.title, l.price, l.category, l.town, l.region,' => 'l.id, l.title, l.price, l.category, l.town,',
    'l.id, l.title, l.category, l.town, l.region,' => 'l.id, l.title, l.category, l.town,',
    ', l.region,' => ',',
    ', l.region ' => ' ',
    ' l.region,' => '',
];

$total = 0;
foreach ($replacements as $old => $new) {
    $count = substr_count($c, $old);
    if ($count > 0) {
        $c = str_replace($old, $new, $c);
        echo "Replaced '$old' x$count\n";
        $total += $count;
    }
}

file_put_contents($f, $c);
echo "\nTotal replacements: $total\n";
echo "File size: " . filesize($f) . " bytes (was $before)\n";
echo shell_exec('php -l ' . $f);
echo "Done!\n";
