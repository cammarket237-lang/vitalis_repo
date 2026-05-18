<?php
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);

// Check if toggle_stock already exists
if (strpos($c, "action === 'toggle_stock'") !== false) {
    echo "toggle_stock already exists!\n";
    exit;
}

$patch = '

// ── TOGGLE STOCK STATUS ───────────────────────────────
if ($action === \'toggle_stock\') {
    $user = authUser();
    if (!$user || $user[\'role\'] !== \'seller\') fail(\'Sellers only.\');
    $listingId  = intval(p(\'listing_id\'));
    $newStatus  = p(\'stock_status\');
    $allowed    = [\'in_stock\', \'out_of_stock\', \'coming_soon\'];
    if (!in_array($newStatus, $allowed)) fail(\'Invalid stock status.\');
    // Verify listing belongs to this seller
    $listing = q1("SELECT id FROM cammarket237.listings WHERE id=? AND store_id IN (SELECT id FROM cammarket237.stores WHERE user_id=?)", [$listingId, $user[\'id\']]);
    if (!$listing) fail(\'Listing not found or not yours.\');
    db()->prepare("UPDATE cammarket237.listings SET stock_status=?, updated_at=NOW() WHERE id=?")->execute([$newStatus, $listingId]);
    ok([\'message\' => \'Stock status updated to: \' . $newStatus, \'stock_status\' => $newStatus]);
}

';

$c .= $patch;
file_put_contents($f, $c);
echo shell_exec('php -l ' . $f . ' 2>&1');
echo "toggle_stock API added!\n";
echo "Size: " . filesize($f) . "\n";
