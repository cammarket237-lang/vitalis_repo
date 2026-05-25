<?php
// ONE-TIME SCRIPT — run once on production, then delete.
// Sends the Ads launch announcement to every buyer & seller on CamMarket237.
// Usage: php broadcast_ads_launch.php
//   OR visit https://cammarket237.com/broadcast_ads_launch.php?secret=CamAdmin2024!

define('SECRET', 'CamAdmin2024!');
if (php_sapi_name() !== 'cli') {
    if (($_GET['secret'] ?? '') !== SECRET) { http_response_code(403); die('Forbidden'); }
}

$dsn  = 'pgsql:host=localhost;dbname=cammarket237_db';
$user = 'cammarket_user';
$pass = getenv('DB_PASS') ?: 'CamMarket2024';

try {
    $db = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    die("DB connect failed: " . $e->getMessage() . "\n");
}

$message = '&#x1F4E3; Advertise on CamMarket237 is now live! Boost your listing, run video ads, sponsor notifications &amp; promote events. Tap &#x1F4E3; Advertise on the home screen to get started &#x1F680;';
$type    = 'admin_announcement';

$users = $db->query("SELECT id FROM cammarket237.users WHERE role IN ('buyer','seller')")->fetchAll(PDO::FETCH_COLUMN);
$stmt  = $db->prepare(
    "INSERT INTO cammarket237.cart_notifications (buyer_id, listing_id, notification_type, message, created_at)
     VALUES (?, 0, ?, ?, NOW())"
);

$sent = 0;
foreach ($users as $uid) {
    try { $stmt->execute([$uid, $type, $message]); $sent++; } catch (Exception $e) {}
}

echo "✅ Broadcast sent to $sent users.\n";
echo "🗑️  Delete this file now: rm /var/www/cammarket237/broadcast_ads_launch.php\n";
