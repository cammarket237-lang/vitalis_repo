<?php
// DEALS SYSTEM - Database migration + API endpoints
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);
$db_host = file_exists('/.dockerenv') ? 'db' : 'localhost';

// Create deals table
try {
    $pdo = new PDO("pgsql:host=$db_host;dbname=cammarket237_db", 'cammarket_user', 'CamMarket2024');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cammarket237.listing_deals (
            id SERIAL PRIMARY KEY,
            listing_id INTEGER NOT NULL REFERENCES cammarket237.listings(id) ON DELETE CASCADE,
            store_id INTEGER NOT NULL,
            seller_id INTEGER NOT NULL,
            deal_type VARCHAR(20) DEFAULT 'custom',
            discount_percent NUMERIC(5,2) NOT NULL,
            original_price NUMERIC(12,2) NOT NULL,
            deal_price NUMERIC(12,2) NOT NULL,
            starts_at TIMESTAMP DEFAULT NOW(),
            ends_at TIMESTAMP NOT NULL,
            is_active BOOLEAN DEFAULT TRUE,
            views INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT NOW()
        );
        CREATE INDEX IF NOT EXISTS idx_deals_active ON cammarket237.listing_deals(is_active, ends_at);
        CREATE INDEX IF NOT EXISTS idx_deals_listing ON cammarket237.listing_deals(listing_id);
    ");
    echo "Deals table created!\n";
} catch(Exception $e) {
    echo "DB: " . $e->getMessage() . "\n";
}

// Add API endpoints
$patch = '

// ═══════════════════════════════════════════════════════════
// DEALS SYSTEM
// ═══════════════════════════════════════════════════════════

if ($action === \'create_deal\') {
    $user = authUser();
    if (!$user || $user[\'role\'] !== \'seller\') fail(\'Sellers only.\');

    $listingId  = intval(p(\'listing_id\'));
    $discount   = floatval(p(\'discount_percent\'));
    $dealType   = p(\'deal_type\') ?: \'custom\';
    $duration   = p(\'duration\') ?: \'24h\';

    if (!$listingId || $discount <= 0 || $discount > 90) fail(\'Invalid deal parameters.\');

    // Get listing
    $listing = q1("SELECT * FROM cammarket237.listings WHERE id=? AND store_id IN (SELECT id FROM cammarket237.stores WHERE user_id=?)",
        [$listingId, $user[\'id\']]);
    if (!$listing) fail(\'Listing not found or not yours.\');

    $originalPrice = floatval($listing[\'price\']);
    $dealPrice = round($originalPrice * (1 - $discount/100));

    // Calculate end time
    $hours = [\'24h\'=>24, \'3d\'=>72, \'1w\'=>168, \'2w\'=>336];
    $h = isset($hours[$duration]) ? $hours[$duration] : 24;
    $endsAt = date(\'Y-m-d H:i:s\', strtotime(\'+\'.$h.\' hours\'));

    // Deactivate existing deal for this listing
    db()->prepare("UPDATE cammarket237.listing_deals SET is_active=false WHERE listing_id=?")->execute([$listingId]);

    // Create new deal
    $store = q1("SELECT id FROM cammarket237.stores WHERE user_id=?", [$user[\'id\']]);
    db()->prepare("INSERT INTO cammarket237.listing_deals
        (listing_id, store_id, seller_id, deal_type, discount_percent, original_price, deal_price, ends_at)
        VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$listingId, $store[\'id\'], $user[\'id\'], $dealType, $discount, $originalPrice, $dealPrice, $endsAt]);

    // Update listing price to deal price
    db()->prepare("UPDATE cammarket237.listings SET price=?, original_price=?, price_drop_active=true WHERE id=?")
        ->execute([$dealPrice, $originalPrice, $listingId]);

    ok([\'message\' => \'Deal created!\', \'deal_price\' => $dealPrice, \'ends_at\' => $endsAt, \'saves\' => ($originalPrice - $dealPrice)]);
}

if ($action === \'end_deal\') {
    $user = authUser();
    if (!$user || $user[\'role\'] !== \'seller\') fail(\'Sellers only.\');
    $listingId = intval(p(\'listing_id\'));
    $deal = q1("SELECT * FROM cammarket237.listing_deals WHERE listing_id=? AND seller_id=? AND is_active=true",
        [$listingId, $user[\'id\']]);
    if (!$deal) fail(\'No active deal found.\');
    // Restore original price
    db()->prepare("UPDATE cammarket237.listings SET price=?, price_drop_active=false WHERE id=?")->execute([$deal[\'original_price\'], $listingId]);
    db()->prepare("UPDATE cammarket237.listing_deals SET is_active=false WHERE id=?")->execute([$deal[\'id\']]);
    ok([\'message\' => \'Deal ended. Price restored.\']);
}

if ($action === \'get_deals\') {
    // Auto-expire old deals first
    try {
        $expired = q("SELECT ld.listing_id, ld.original_price FROM cammarket237.listing_deals ld
            WHERE ld.is_active=true AND ld.ends_at < NOW()");
        foreach ($expired as $ex) {
            db()->prepare("UPDATE cammarket237.listings SET price=?, price_drop_active=false WHERE id=?")
                ->execute([$ex[\'original_price\'], $ex[\'listing_id\']]);
            db()->prepare("UPDATE cammarket237.listing_deals SET is_active=false WHERE listing_id=? AND is_active=true")
                ->execute([$ex[\'listing_id\']]);
        }
    } catch(Exception $e) {}

    $deals = q("SELECT ld.*, l.title, l.category, l.town, l.region,
        l.main_photo, l.description, l.listing_type,
        s.store_name, s.whatsapp, s.rating as store_rating,
        EXTRACT(EPOCH FROM (ld.ends_at - NOW())) AS seconds_left
        FROM cammarket237.listing_deals ld
        JOIN cammarket237.listings l ON l.id=ld.listing_id
        JOIN cammarket237.stores s ON s.id=ld.store_id
        WHERE ld.is_active=true AND ld.ends_at > NOW()
        AND l.status=\'active\'
        ORDER BY ld.discount_percent DESC, ld.created_at DESC
        LIMIT 20");

    ok([\'deals\' => $deals]);
}

if ($action === \'get_my_deals\') {
    $user = authUser();
    if (!$user) fail(\'Login required.\');
    $deals = q("SELECT ld.*, l.title, l.main_photo,
        EXTRACT(EPOCH FROM (ld.ends_at - NOW())) AS seconds_left
        FROM cammarket237.listing_deals ld
        JOIN cammarket237.listings l ON l.id=ld.listing_id
        WHERE ld.seller_id=? AND ld.is_active=true
        ORDER BY ld.created_at DESC", [$user[\'id\']]);
    ok([\'deals\' => $deals]);
}

';

$c .= $patch;
file_put_contents($f, $c);
echo shell_exec('php -l ' . $f . ' 2>&1');
echo "Deals API added!\n";
echo "Size: " . filesize($f) . "\n";
