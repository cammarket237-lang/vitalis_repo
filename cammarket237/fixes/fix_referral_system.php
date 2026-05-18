<?php
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);

$patch = '

// ═══════════════════════════════════════════════════════════
// REFERRAL & INVITE SYSTEM
// ═══════════════════════════════════════════════════════════

if ($action === \'get_referral_stats\') {
    $user = authUser();
    if (!$user) fail(\'Login required.\');

    // Get referral stats
    $referrals = q("SELECT u.full_name, u.role, u.created_at,
        CASE WHEN u.role=\'buyer\' THEN 50 ELSE 100 END AS points_earned
        FROM cammarket237.users u
        WHERE u.referred_by = ?
        ORDER BY u.created_at DESC
        LIMIT 20", [$user[\'id\']]);

    $totalReferrals = count($referrals);
    $totalPoints = array_sum(array_column($referrals, \'points_earned\'));

    // Get rank
    $rankRow = q1("SELECT COUNT(*) + 1 AS rank
        FROM (SELECT referred_by, COUNT(*) AS cnt
              FROM cammarket237.users
              WHERE referred_by IS NOT NULL
              GROUP BY referred_by) t
        WHERE t.cnt > (SELECT COUNT(*) FROM cammarket237.users WHERE referred_by=?)",
        [$user[\'id\']]);

    ok([
        \'referral_code\'   => $user[\'referral_code\'],
        \'promo_points\'    => intval($user[\'promo_points\'] ?? 0) + $totalPoints,
        \'total_referrals\' => $totalReferrals,
        \'rank\'            => $rankRow ? intval($rankRow[\'rank\']) : 1,
        \'referrals\'       => $referrals,
    ]);
}

';

$c .= $patch;
file_put_contents($f, $c);
echo shell_exec('php -l ' . $f . ' 2>&1');

// Also update register_buyer and register_seller to handle promo_code
// Find and update buyer registration to award referral points
$oldBuyer = "ok(['user'=>\$user,'message'=>'Welcome to CamMarket237, '.\$name.'!']);";
$newBuyer = "    // Award referral points if promo code used
    \$promoCode = strtoupper(trim(p('promo_code')));
    if (\$promoCode) {
        try {
            \$referrer = q1(\"SELECT id FROM cammarket237.users WHERE referral_code=? LIMIT 1\", [\$promoCode]);
            if (\$referrer && \$referrer['id'] !== \$userId) {
                // Set referred_by
                db()->prepare(\"UPDATE cammarket237.users SET referred_by=? WHERE id=?\")->execute([\$referrer['id'], \$userId]);
                // Give referrer 50 points
                db()->prepare(\"UPDATE cammarket237.users SET promo_points=COALESCE(promo_points,0)+50 WHERE id=?\")->execute([\$referrer['id']]);
                // Give new user 20 bonus points
                db()->prepare(\"UPDATE cammarket237.users SET promo_points=COALESCE(promo_points,0)+20 WHERE id=?\")->execute([\$userId]);
                \$user['promo_points'] = 20;
            }
        } catch(Exception \$ex) {}
    }
    ok(['user'=>\$user,'message'=>'Welcome to CamMarket237, '.\$name.'!']);";

if (strpos($c, $oldBuyer) !== false) {
    $c = str_replace($oldBuyer, $newBuyer, $c);
    echo "Buyer referral points added!\n";
}

// Seller referral
$oldSeller = "'message' => 'Seller account created! Welcome ' . \$name . '. You got 30 FREE streaming minutes!',";
$newSeller = "'message' => 'Seller account created! Welcome ' . \$name . '. You got 30 FREE streaming minutes!',
            'promo_bonus' => \$promoBonus ?? false,";

// Add promo code handling before seller ok()
$oldSellerOk = "        // Give 30 free streaming minutes to first 200 sellers";
$newSellerOk = "        // Handle referral promo code
        \$promoCode = strtoupper(trim(p('promo_code')));
        \$promoBonus = false;
        if (\$promoCode) {
            try {
                \$referrer = q1(\"SELECT id FROM cammarket237.users WHERE referral_code=? LIMIT 1\", [\$promoCode]);
                if (\$referrer && \$referrer['id'] !== \$userId) {
                    db()->prepare(\"UPDATE cammarket237.users SET referred_by=? WHERE id=?\")->execute([\$referrer['id'], \$userId]);
                    // Give referrer 100 points for seller referral
                    db()->prepare(\"UPDATE cammarket237.users SET promo_points=COALESCE(promo_points,0)+100 WHERE id=?\")->execute([\$referrer['id']]);
                    \$promoBonus = true;
                }
            } catch(Exception \$ex) {}
        }

        // Give 30 free streaming minutes to first 200 sellers";

if (strpos($c, $oldSellerOk) !== false) {
    $c = str_replace($oldSellerOk, $newSellerOk, $c);
    echo "Seller referral points added!\n";
}

file_put_contents($f, $c);
echo shell_exec('php -l ' . $f . ' 2>&1');
echo "Size: " . filesize($f) . "\n";
echo "Referral system complete!\n";
