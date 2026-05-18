<?php
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);

// Remove the incorrectly placed code (after ok())
$bad = "        ]);
        // Give 30 free streaming minutes to first 200 sellers
        try {
            \$sellerCount = q1(\"SELECT COUNT(*) AS n FROM cammarket237.users WHERE role='seller'\");
            if (intval(\$sellerCount['n']) <= 200) {
                \$bal = q1(\"SELECT id FROM cammarket237.stream_balance WHERE seller_id=?\", [\$userId]);
                if (!\$bal) {
                    db()->prepare(\"INSERT INTO cammarket237.stream_balance (seller_id,minutes_available,first_purchase_bonus_given) VALUES (?,30,false)\")->execute([\$userId]);
                } else {
                    db()->prepare(\"UPDATE cammarket237.stream_balance SET minutes_available=minutes_available+30 WHERE seller_id=?\")->execute([\$userId]);
                }
                db()->prepare(\"INSERT INTO cammarket237.stream_transactions (seller_id,transaction_type,minutes_added,amount_fcfa,note) VALUES (?,'weekly_free',30,0,'Welcome bonus - 30 free mins (first 200 sellers)')\")->execute([\$userId]);
            }
        } catch(Exception \$ex) {}";

$good = "        ]);";

// Put 30 mins code BEFORE ok()
$before_ok = "        // Give 30 free streaming minutes to first 200 sellers
        try {
            \$sellerCount = q1(\"SELECT COUNT(*) AS n FROM cammarket237.users WHERE role='seller'\");
            if (intval(\$sellerCount['n']) <= 200) {
                \$bal = q1(\"SELECT id FROM cammarket237.stream_balance WHERE seller_id=?\", [\$user['id']]);
                if (!\$bal) {
                    db()->prepare(\"INSERT INTO cammarket237.stream_balance (seller_id,minutes_available,first_purchase_bonus_given) VALUES (?,30,false)\")->execute([\$user['id']]);
                } else {
                    db()->prepare(\"UPDATE cammarket237.stream_balance SET minutes_available=minutes_available+30 WHERE seller_id=?\")->execute([\$user['id']]);
                }
                db()->prepare(\"INSERT INTO cammarket237.stream_transactions (seller_id,transaction_type,minutes_added,amount_fcfa,note) VALUES (?,'weekly_free',30,0,'Welcome bonus - 30 free mins (first 200 sellers)')\")->execute([\$user['id']]);
            }
        } catch(Exception \$ex) {}
        ok([
            'user'    => \$user,
            'store'   => \$storeArr,
            'message' => 'Seller account created! Welcome ' . \$name . '. You got 30 FREE streaming minutes!',
        ]);";

// Step 1: Remove bad placement
if (strpos($c, $bad) !== false) {
    $c = str_replace($bad, $good, $c);
    echo "Removed bad placement!\n";
}

// Step 2: Add before ok()
$target = "        ok([
            'user'    => \$user,
            'store'   => \$storeArr,
            'message' => 'Seller account created! Welcome ' . \$name . '. You got 30 FREE streaming minutes!',
        ]);";

if (strpos($c, $target) !== false) {
    $c = str_replace($target, $before_ok, $c);
    echo "30 mins added BEFORE ok()!\n";
} else {
    echo "Target not found\n";
}

file_put_contents($f, $c);
echo shell_exec('php -l ' . $f . ' 2>&1');
echo "Size: " . filesize($f) . "\n";
