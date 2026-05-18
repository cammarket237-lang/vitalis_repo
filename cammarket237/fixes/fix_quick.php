<?php
$f = "/var/www/cammarket237/api.php";
$c = file_get_contents($f);

$old = "    \$listings = q(\"SELECT l.id, l.title, l.price, l.category, l.town,
        l.main_photo, l.condition, l.created_at,
        s.store_name, s.whatsapp, s.rating as store_rating,
        u.full_name as seller_name
        FROM cammarket237.listings l
        JOIN cammarket237.stores s ON s.id=l.store_id
        JOIN cammarket237.users u ON u.id=s.user_id
        WHERE \$wClause";

$new = "    \$listings = q(\"SELECT l.id, l.title, l.price, l.category, l.town,
        lm.media_url AS main_photo, l.condition, l.created_at,
        s.store_name, s.whatsapp, s.rating as store_rating,
        u.full_name as seller_name
        FROM cammarket237.listings l
        JOIN cammarket237.stores s ON s.id=l.store_id
        JOIN cammarket237.users u ON u.id=s.user_id
        LEFT JOIN cammarket237.listing_media lm ON lm.listing_id=l.id AND lm.media_role='main_image'
        WHERE \$wClause";

if (strpos($c, $old) !== false) {
    $c = str_replace($old, $new, $c);
    file_put_contents($f, $c);
    echo "FIXED!\n";
} else {
    echo "Pattern still not found\n";
    // Brute force - just replace l.main_photo with subquery
    $c2 = str_replace("        l.main_photo, l.condition, l.created_at,", "        (SELECT media_url FROM cammarket237.listing_media WHERE listing_id=l.id AND media_role='main_image' LIMIT 1) AS main_photo, l.condition, l.created_at,", $c);
    if ($c !== $c2) {
        file_put_contents($f, $c2);
        echo "FIXED with subquery!\n";
    }
}
echo shell_exec("php -l " . $f);

