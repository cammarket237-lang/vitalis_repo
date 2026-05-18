<?php
// Fix get_deals and get_new_items returning empty responses
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);

// Check current get_deals
$pos = strpos($c, "action === 'get_deals'");
echo "get_deals at line: ";
echo substr_count(substr($c, 0, $pos), "\n") + 1;
echo "\n";

// Check get_new_items
$pos2 = strpos($c, "action === 'get_new_items'");
echo "get_new_items at line: ";
echo substr_count(substr($c, 0, $pos2), "\n") + 1;
echo "\n";

// Test deals table
$host = file_exists('/.dockerenv') ? 'db' : 'localhost';
$pdo = new PDO("pgsql:host=$host;dbname=cammarket237_db", 'cammarket_user', 'CamMarket2024');
$count = $pdo->query("SELECT COUNT(*) FROM cammarket237.listing_deals")->fetchColumn();
echo "Deals in DB: $count\n";

// Check if listing_deals has main_photo column issue
$deals = $pdo->query("SELECT ld.*, l.title, l.category, l.town,
    l.description, l.listing_type,
    s.store_name, s.whatsapp, s.rating as store_rating,
    EXTRACT(EPOCH FROM (ld.ends_at - NOW())) AS seconds_left
    FROM cammarket237.listing_deals ld
    JOIN cammarket237.listings l ON l.id=ld.listing_id
    JOIN cammarket237.stores s ON s.id=ld.store_id
    WHERE ld.is_active=true AND ld.ends_at > NOW()
    LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);
echo "Deal query works: " . (count($deals) > 0 ? "yes" : "no deals active") . "\n";

// Check new_items query
$items = $pdo->query("SELECT l.id, l.title, l.price, l.category, l.town,
    l.created_at, s.store_name
    FROM cammarket237.listings l
    JOIN cammarket237.stores s ON s.id=l.store_id
    WHERE l.status='active'
    AND l.created_at > NOW() - INTERVAL '48 hours'
    AND COALESCE(l.moderation_status,'approved')='approved'
    ORDER BY l.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "New items (48h): " . count($items) . "\n";
foreach ($items as $i) echo "  - {$i['title']} ({$i['category']})\n";

// The issue: get_deals queries main_photo which doesn't exist as column
// It needs to come from listing_media
// Fix the get_deals query to get photo from listing_media
$old_deals_q = "SELECT ld.*, l.title, l.category, l.town,
        l.main_photo, l.description, l.listing_type,
        s.store_name, s.whatsapp, s.rating as store_rating,
        EXTRACT(EPOCH FROM (ld.ends_at - NOW())) AS seconds_left
        FROM cammarket237.listing_deals ld
        JOIN cammarket237.listings l ON l.id=ld.listing_id
        JOIN cammarket237.stores s ON s.id=ld.store_id
        WHERE ld.is_active=true AND ld.ends_at > NOW()
        AND l.status='active'
        ORDER BY ld.discount_percent DESC, ld.created_at DESC
        LIMIT 20";

$new_deals_q = "SELECT ld.*, l.title, l.category, l.town,
        lm.media_url AS main_photo, l.description, l.listing_type,
        s.store_name, s.whatsapp, s.rating as store_rating,
        EXTRACT(EPOCH FROM (ld.ends_at - NOW())) AS seconds_left
        FROM cammarket237.listing_deals ld
        JOIN cammarket237.listings l ON l.id=ld.listing_id
        JOIN cammarket237.stores s ON s.id=ld.store_id
        LEFT JOIN cammarket237.listing_media lm ON lm.listing_id=l.id AND lm.media_role='main_image'
        WHERE ld.is_active=true AND ld.ends_at > NOW()
        AND l.status='active'
        ORDER BY ld.discount_percent DESC, ld.created_at DESC
        LIMIT 20";

if (strpos($c, $old_deals_q) !== false) {
    $c = str_replace($old_deals_q, $new_deals_q, $c);
    echo "get_deals query fixed!\n";
} else {
    echo "get_deals pattern not found - checking alternate\n";
}

// Fix get_new_items query - remove main_photo column
$old_new_q = "SELECT l.id, l.title, l.price, l.category, l.town, l.region,
        l.main_photo, l.condition, l.created_at,
        s.store_name, s.whatsapp, s.rating as store_rating,
        u.full_name as seller_name";

$new_new_q = "SELECT l.id, l.title, l.price, l.category, l.town,
        lm.media_url AS main_photo, l.condition, l.created_at,
        s.store_name, s.whatsapp, s.rating as store_rating,
        u.full_name as seller_name";

if (strpos($c, $old_new_q) !== false) {
    $c = str_replace($old_new_q, $new_new_q, $c);
    // Also fix the JOIN
    $old_join = "FROM cammarket237.listings l
        JOIN cammarket237.stores s ON s.id=l.store_id
        JOIN cammarket237.users u ON u.id=s.user_id
        WHERE $wClause";
    // Find and update the FROM clause
    echo "get_new_items photo fixed!\n";
} else {
    echo "get_new_items pattern not found\n";
}

// Also fix get_new_items FROM clause to include listing_media join
$old_from = "FROM cammarket237.listings l
        JOIN cammarket237.stores s ON s.id=l.store_id
        JOIN cammarket237.users u ON u.id=s.user_id
        WHERE \$wClause
        ORDER BY l.created_at DESC
        LIMIT 30";

$new_from = "FROM cammarket237.listings l
        JOIN cammarket237.stores s ON s.id=l.store_id
        JOIN cammarket237.users u ON u.id=s.user_id
        LEFT JOIN cammarket237.listing_media lm ON lm.listing_id=l.id AND lm.media_role='main_image'
        WHERE \$wClause
        ORDER BY l.created_at DESC
        LIMIT 30";

if (strpos($c, $old_from) !== false) {
    $c = str_replace($old_from, $new_from, $c);
    echo "get_new_items FROM clause fixed!\n";
}

file_put_contents($f, $c);
echo shell_exec('php -l ' . $f . ' 2>&1');
echo "Size: " . filesize($f) . "\n";
