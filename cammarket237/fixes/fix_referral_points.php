<?php
// Fix referral points to match original system:
// - Referrer gets 5 points when someone uses their code
// - New user gets 10 points for using a referral code
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);

// Fix buyer referral - change 50 -> 5 (referrer) and 20 -> 10 (new user)
$old1 = '                db()->prepare("UPDATE cammarket237.users SET promo_points=COALESCE(promo_points,0)+50 WHERE id=?")->execute([$referrer[\'id\']]);
                // Give new user 20 bonus points
                db()->prepare("UPDATE cammarket237.users SET promo_points=COALESCE(promo_points,0)+20 WHERE id=?")->execute([$userId]);
                $user[\'promo_points\'] = 20;';

$new1 = '                // Referrer gets 5 points
                db()->prepare("UPDATE cammarket237.users SET promo_points=COALESCE(promo_points,0)+5 WHERE id=?")->execute([$referrer[\'id\']]);
                // New user gets 10 points
                db()->prepare("UPDATE cammarket237.users SET promo_points=COALESCE(promo_points,0)+10 WHERE id=?")->execute([$userId]);
                $user[\'promo_points\'] = 10;';

if (strpos($c, $old1) !== false) {
    $c = str_replace($old1, $new1, $c);
    echo "Buyer points fixed: referrer=5, new user=10\n";
}

// Fix seller referral - change 100 -> 5 (referrer)
$old2 = '                    // Give referrer 100 points for seller referral
                    db()->prepare("UPDATE cammarket237.users SET promo_points=COALESCE(promo_points,0)+100 WHERE id=?")->execute([$referrer[\'id\']]);';

$new2 = '                    // Referrer gets 5 points for seller referral
                    db()->prepare("UPDATE cammarket237.users SET promo_points=COALESCE(promo_points,0)+5 WHERE id=?")->execute([$referrer[\'id\']]);';

if (strpos($c, $old2) !== false) {
    $c = str_replace($old2, $new2, $c);
    echo "Seller points fixed: referrer=5\n";
}

// Fix stats display - points_earned should be 5 not 50/100
$old3 = "CASE WHEN u.role='buyer' THEN 50 ELSE 100 END AS points_earned";
$new3 = "5 AS points_earned";
if (strpos($c, $old3) !== false) {
    $c = str_replace($old3, $new3, $c);
    echo "Stats points_earned fixed to 5\n";
}

file_put_contents($f, $c);
echo shell_exec('php -l ' . $f . ' 2>&1');
echo "Size: " . filesize($f) . "\n";
echo "Points system corrected!\n";
