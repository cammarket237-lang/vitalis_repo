<?php
// Fix get_deals and get_my_deals queries on local Docker
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);

// Fix 1: Remove l.region (doesn't exist)
$c = str_replace(
    'l.title, l.category, l.town, l.region,',
    'l.title, l.category, l.town,',
    $c
);

// Fix 2: Replace l.main_photo with JOIN to listing_media in get_deals
$old1 = "l.title, l.category, l.town,
        l.main_photo, l.description, l.listing_type,
        s.store_name, s.whatsapp, s.rating as store_rating,
        EXTRACT(EPOCH FROM (ld.ends_at - NOW())) AS seconds_left
        FROM cammarket237.listing_deals ld
        JOIN cammarket237.listings l ON l.id=ld.listing_id
        JOIN cammarket237.stores s ON s.id=ld.store_id";

$new1 = "l.title, l.category, l.town,
        lm.media_url AS main_photo, l.description, l.listing_type,
        s.store_name, s.whatsapp, s.rating as store_rating,
        EXTRACT(EPOCH FROM (ld.ends_at - NOW())) AS seconds_left
        FROM cammarket237.listing_deals ld
        JOIN cammarket237.listings l ON l.id=ld.listing_id
        JOIN cammarket237.stores s ON s.id=ld.store_id
        LEFT JOIN cammarket237.listing_media lm ON lm.listing_id=l.id AND lm.media_role='main_image'";

$c = str_replace($old1, $new1, $c);

// Fix 3: get_my_deals - same JOIN
$c = str_replace(
    'SELECT ld.*, l.title, l.main_photo,',
    'SELECT ld.*, l.title, lm.media_url AS main_photo,',
    $c
);

$old2 = "FROM cammarket237.listing_deals ld
        JOIN cammarket237.listings l ON l.id=ld.listing_id
        WHERE ld.seller_id=?";

$new2 = "FROM cammarket237.listing_deals ld
        JOIN cammarket237.listings l ON l.id=ld.listing_id
        LEFT JOIN cammarket237.listing_media lm ON lm.listing_id=l.id AND lm.media_role='main_image'
        WHERE ld.seller_id=?";

$c = str_replace($old2, $new2, $c);

// Fix 4: Remove 'seen' column (doesn't exist)
$c = str_replace(
    'WHERE buyer_id=? AND seen=false',
    'WHERE buyer_id=?',
    $c
);

// Fix 5: Replace otp_code with token
$c = str_replace('otp_code', 'token', $c);

// Fix 6: DELETE all OTPs (unique constraint)
$c = str_replace(
    "DELETE FROM cammarket237.otp_tokens WHERE phone=? AND purpose='reset'",
    "DELETE FROM cammarket237.otp_tokens WHERE phone=?",
    $c
);

// Fix 7: Bad SELECT DISTINCT with ORDER BY MAX (smart_feed)
$bad_sql = '        $recent = q("$baseSelect AND l.id IN (
            SELECT DISTINCT listing_id FROM cammarket237.buyer_events
            WHERE $field=? AND listing_id IS NOT NULL AND event_type=\'view\'
            ORDER BY MAX(created_at) DESC LIMIT 8
        ) LIMIT 8", [$param]);
        // Fix: use subquery properly
        $recentIds';

$good_sql = '        // Use subquery properly to get recent listings
        $recentIds';

$c = str_replace($bad_sql, $good_sql, $c);

file_put_contents($f, $c);

echo "Applied fixes:\n";
echo "1. Removed l.region column references\n";
echo "2. Replaced l.main_photo with listing_media JOIN\n";
echo "3. Fixed get_my_deals JOIN\n";
echo "4. Removed 'seen' column reference\n";
echo "5. otp_code → token\n";
echo "6. DELETE all OTPs (not just reset)\n";
echo "7. Removed bad SELECT DISTINCT\n";
echo "\n";
echo shell_exec('php -l ' . $f);
echo "File size: " . filesize($f) . " bytes\n";
