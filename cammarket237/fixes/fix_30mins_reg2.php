<?php
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);

$old = "            'message' => 'Seller account created! Welcome ' . \$name . '.',
        ]);";

$new = "            'message' => 'Seller account created! Welcome ' . \$name . '. You got 30 FREE streaming minutes!',
        ]);

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

if (strpos($c, $old) !== false) {
    $c = str_replace($old, $new, $c);
    file_put_contents($f, $c);
    echo "30 free mins added!\n";
    echo shell_exec('php -l ' . $f . ' 2>&1');
    echo "Size: " . filesize($f) . "\n";
} else {
    echo "Pattern not found\n";
    // Show context around line 373
    $lines = explode("\n", $c);
    for ($i = 370; $i <= 378; $i++) {
        echo ($i+1) . ": " . $lines[$i] . "\n";
    }
}
