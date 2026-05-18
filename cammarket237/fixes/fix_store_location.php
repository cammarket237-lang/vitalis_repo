<?php
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);

$patch = '

// ── UPDATE STORE LOCATION ─────────────────────────────────
if ($action === \'update_store_location\') {
    $user = authUser();
    if (!$user || $user[\'role\'] !== \'seller\') fail(\'Sellers only.\');
    $lat = floatval(p(\'lat\'));
    $lng = floatval(p(\'lng\'));
    if (!$lat || !$lng) fail(\'Invalid coordinates.\');
    try {
        db()->prepare("UPDATE cammarket237.stores SET latitude=?, longitude=?, location_verified=true, location_updated_at=NOW() WHERE user_id=?")
            ->execute([$lat, $lng, $user[\'id\']]);
        ok([\'message\' => \'Store location updated!\', \'lat\' => $lat, \'lng\' => $lng]);
    } catch(Exception $e) { fail($e->getMessage()); }
}

';

$c .= $patch;
file_put_contents($f, $c);
echo shell_exec('php -l ' . $f . ' 2>&1');
echo "update_store_location added!\n";
echo "Size: " . filesize($f) . "\n";
