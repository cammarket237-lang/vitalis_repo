<?php
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);

$patch = '

// ── NEW ITEMS NOTIFICATIONS ───────────────────────────────

if ($action === \'get_new_items_count\') {
    $hours = 48;
    $row = q1("SELECT COUNT(*) AS cnt FROM cammarket237.listings l
        JOIN cammarket237.stores s ON s.id=l.store_id
        WHERE l.status=\'active\'
        AND l.created_at > NOW() - INTERVAL \'' . $hours . ' hours\'
        AND COALESCE(l.moderation_status,\'approved\')=\'approved\'");
    ok([\'count\' => intval($row[\'cnt\'])]);
}

if ($action === \'get_new_items\') {
    $hours = 48;
    $town   = g(\'town\') ?: \'\';
    $region = g(\'region\') ?: \'\';

    $where = ["l.status=\'active\'",
              "l.created_at > NOW() - INTERVAL \'" . $hours . " hours\'",
              "COALESCE(l.moderation_status,\'approved\')=\'approved\'"];
    $params = [];

    if ($town)   { $where[] = "l.town=?";   $params[] = $town; }
    if ($region) { $where[] = "l.region=?"; $params[] = $region; }

    $wClause = implode(" AND ", $where);

    $listings = q("SELECT l.id, l.title, l.price, l.category, l.town, l.region,
        l.main_photo, l.condition, l.created_at,
        s.store_name, s.whatsapp, s.rating as store_rating,
        u.full_name as seller_name
        FROM cammarket237.listings l
        JOIN cammarket237.stores s ON s.id=l.store_id
        JOIN cammarket237.users u ON u.id=s.user_id
        WHERE $wClause
        ORDER BY l.created_at DESC
        LIMIT 30", $params);

    ok([\'listings\' => $listings, \'hours\' => $hours]);
}

';

$c .= $patch;
file_put_contents($f, $c);
echo shell_exec('php -l ' . $f . ' 2>&1');
echo "New items API added!\n";
echo "Size: " . filesize($f) . "\n";
