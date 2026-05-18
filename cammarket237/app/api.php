<?php

function checkRateLimit($identifier, $action, $maxAttempts = 5, $windowSeconds = 300) {
    $key = $action . ':' . $identifier;
    try {
        $now = date('Y-m-d H:i:s');
        $ws  = date('Y-m-d H:i:s', time() - $windowSeconds);
        $r   = q1("SELECT * FROM cammarket237.rate_limits WHERE rate_key=?", [$key]);
        if (!$r) {
            db()->prepare("INSERT INTO cammarket237.rate_limits (rate_key,attempts) VALUES (?,1)")->execute([$key]);
            return ['allowed'=>true,'attempts'=>1,'remaining'=>$maxAttempts-1];
        }
        if ($r['blocked_until'] && $r['blocked_until'] > $now) {
            $w = strtotime($r['blocked_until']) - time();
            return ['allowed'=>false,'attempts'=>$r['attempts'],'wait_seconds'=>$w,'wait_minutes'=>ceil($w/60)];
        }
        if ($r['first_attempt_at'] < $ws) {
            db()->prepare("UPDATE cammarket237.rate_limits SET attempts=1,first_attempt_at=NOW(),last_attempt_at=NOW(),blocked_until=NULL WHERE rate_key=?")->execute([$key]);
            return ['allowed'=>true,'attempts'=>1,'remaining'=>$maxAttempts-1];
        }
        $n = $r['attempts'] + 1;
        if ($n >= $maxAttempts) {
            $bm = min(60, 5 * pow(2, floor($n/$maxAttempts)-1));
            $bu = date('Y-m-d H:i:s', time()+($bm*60));
            db()->prepare("UPDATE cammarket237.rate_limits SET attempts=?,last_attempt_at=NOW(),blocked_until=? WHERE rate_key=?")->execute([$n,$bu,$key]);
            return ['allowed'=>false,'attempts'=>$n,'wait_minutes'=>$bm,'wait_seconds'=>$bm*60];
        }
        db()->prepare("UPDATE cammarket237.rate_limits SET attempts=?,last_attempt_at=NOW() WHERE rate_key=?")->execute([$n,$key]);
        return ['allowed'=>true,'attempts'=>$n,'remaining'=>$maxAttempts-$n];
    } catch(Exception $e) {
        return ['allowed'=>true,'attempts'=>0,'remaining'=>$maxAttempts];
    }
}

function resetRateLimit($id, $action) {
    try {
        db()->prepare("DELETE FROM cammarket237.rate_limits WHERE rate_key=?")->execute([$action.':'.$id]);
    } catch(Exception $e) {}
}

function logWalletTx($userId, $amount, $type, $note = '') {
    static $tableReady = false;
    try {
        if (!$tableReady) {
            db()->prepare("CREATE TABLE IF NOT EXISTS cammarket237.wallet_transactions (
                id SERIAL PRIMARY KEY, user_id INTEGER NOT NULL, amount_fcfa INTEGER NOT NULL,
                type VARCHAR(50) NOT NULL, note VARCHAR(255), created_at TIMESTAMP DEFAULT NOW()
            )")->execute([]);
            $tableReady = true;
        }
        db()->prepare("INSERT INTO cammarket237.wallet_transactions (user_id, amount_fcfa, type, note) VALUES (?,?,?,?)")
            ->execute([$userId, $amount, $type, $note]);
    } catch(Exception $e) {}
}

function getClientIP() {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
    return trim(explode(',', $ip)[0]);
}
// CamMarket237 — api.php
// Upload to: /var/www/cammarket237/api.php
// ═══════════════════════════════════════════════════════════
header('Content-Type: application/json');
require_once __DIR__.'/verify_photos.php';
$allowedOrigins = ['https://cammarket237.com', 'http://localhost:8080', 'http://localhost'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
} elseif (empty($origin)) {
    header('Access-Control-Allow-Origin: https://cammarket237.com');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Session-Token');
header('Access-Control-Allow-Credentials: true');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── CONFIG ────────────────────────────────────────────────
define('DB_DSN',    'pgsql:host=' . (file_exists('/.dockerenv') ? 'db' : 'localhost') . ';dbname=cammarket237_db');
define('DB_USER',   'cammarket_user');
define('DB_PASS',   'CamMarket2024');       // ← CHANGE THIS
define('CLAUDE_KEY','YOUR_ANTHROPIC_KEY');     // ← CHANGE THIS
define('UPLOAD_DIR','/var/www/cammarket237/uploads/');
define('UPLOAD_URL','/uploads/');
define('SESSION_HOURS', 24); // 24 hours
define('OTP_MINUTES', 10); // 10 minutes

// ── DATABASE ──────────────────────────────────────────────
function db() {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    return $pdo;
}

// ── HELPERS ───────────────────────────────────────────────
function ok($data=[])  { echo json_encode(array_merge(['success'=>true], $data)); exit; }
function fail($msg)    { echo json_encode(['success'=>false,'error'=>$msg]);       exit; }
function p($k)         { return trim($_POST[$k] ?? ''); }
function g($k)         { return trim($_GET[$k]  ?? ''); }
function token()       { return bin2hex(random_bytes(32)); }
function q($sql, $p=[]) {
    $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll();
}
function q1($sql, $p=[]) {
    $s = db()->prepare($sql); $s->execute($p); return $s->fetch();
}

function authUser() {
    // Check header first, then POST body, then GET param, then multipart form
    $t = $_SERVER['HTTP_X_SESSION_TOKEN'] 
      ?? ($_POST['session_token'] ?? null)
      ?? ($_GET['session_token'] ?? null)
      ?? null;
    // Also check raw input for multipart
    if (!$t && !empty($_POST)) $t = $_POST['session_token'] ?? null;
    if (!$t) return null;
    $t = trim($t);
    if (!$t) return null;
    $s = db()->prepare("SELECT * FROM cammarket237.users WHERE session_token=? AND session_expires_at>NOW() AND COALESCE(is_active,true)=true LIMIT 1");
    $s->execute([$t]);
    return $s->fetch() ?: null;
}


// ══════════════════════════════════════════════════════════
// UNIVERSAL PHONE NORMALIZER
// Strips all formatting and generates search variants
// ══════════════════════════════════════════════════════════
function normalizePhone($phone) {
    // Pre-clean: handle +, spaces, URL encoding
    $phone = urldecode(trim($phone));
    
    // Strip ALL non-digit characters to get raw digits
    $digits = preg_replace('/[^0-9]/', '', $phone);
    
    if (!$digits) return [];

    $variants = [];

    // Always include raw digits and + version
    $variants[] = $digits;
    $variants[] = '+' . $digits;

    // ── CAMEROON (237) ──────────────────────────────────
    if (strlen($digits) >= 10 && substr($digits, 0, 3) === '237') {
        $local = substr($digits, 3);
        $variants[] = $local;
        $variants[] = '+237' . $local;
        $variants[] = '237' . $local;
    }
    // 9-digit Cameroon (6XXXXXXXX or 2XXXXXXXX)
    if (strlen($digits) === 9) {
        $variants[] = '237' . $digits;
        $variants[] = '+237' . $digits;
    }
    // 8-digit Cameroon
    if (strlen($digits) === 8) {
        $variants[] = '237' . $digits;
        $variants[] = '+237' . $digits;
    }

    // ── USA/CANADA (1) ──────────────────────────────────
    // 10-digit US (2408388119)
    if (strlen($digits) === 10 && substr($digits, 0, 1) !== '2' && substr($digits, 0, 3) !== '237') {
        $variants[] = '1' . $digits;
        $variants[] = '+1' . $digits;
    }
    // 10-digit starting with any digit - also try as US
    if (strlen($digits) === 10) {
        $variants[] = '1' . $digits;
        $variants[] = '+1' . $digits;
    }
    // 11-digit US with leading 1 (12408388119)
    if (strlen($digits) === 11 && substr($digits, 0, 1) === '1') {
        $local = substr($digits, 1);
        $variants[] = $local;
        $variants[] = '+1' . $local;
        $variants[] = '1' . $local;
    }

    // ── NIGERIA (234) ────────────────────────────────────
    if (strlen($digits) >= 10 && substr($digits, 0, 3) === '234') {
        $local = substr($digits, 3);
        $variants[] = $local;
        $variants[] = '+234' . $local;
    }

    // ── GHANA (233) ──────────────────────────────────────
    if (strlen($digits) >= 10 && substr($digits, 0, 3) === '233') {
        $local = substr($digits, 3);
        $variants[] = $local;
        $variants[] = '+233' . $local;
    }

    // Short fallback
    if (strlen($digits) <= 7) {
        $variants[] = '237' . $digits;
        $variants[] = '+237' . $digits;
        $variants[] = '1' . $digits;
    }

    return array_unique(array_filter($variants));
}


function findUserByPhone($phone, $role = null) {
    $variants = normalizePhone($phone);
    $placeholders = implode(',', array_fill(0, count($variants), '?'));
    $roleClause = $role ? " AND role=?" : "";
    $params = $role ? array_merge($variants, [$role]) : $variants;
    return q1("SELECT * FROM cammarket237.users WHERE phone IN ($placeholders) $roleClause LIMIT 1", $params);
}


$action = g('action') ?: p('action');

// ═══════════════════════════════════════════════════════════
// REGISTER BUYER
// ═══════════════════════════════════════════════════════════
if ($action === 'register_buyer') {
    $name   = p('full_name');
    $phone  = p('phone');
    $pass   = p('password');
    $cpass  = p('confirm_password');
    $region = p('region');
    $town   = p('town');

    $pin = trim(p('recovery_pin'));

    if (!$name||!$phone||!$pass||!$region||!$town) fail('All fields are required.');
    if ($pass !== $cpass)      fail('Passwords do not match.');
    if (strlen($pass) < 6)    fail('Password must be at least 6 characters.');
    if (!preg_match('/^[0-9]{6}$/', $pin)) fail('Recovery PIN must be exactly 6 digits.');
    $weakPins = ['000000','111111','222222','333333','444444','555555','666666','777777','888888','999999','123456','654321','121212','112233','123123'];
    if (in_array($pin, $weakPins)) fail('Please choose a less obvious PIN.');
    $pinHash = password_hash($pin, PASSWORD_DEFAULT);

    $chk = db()->prepare("SELECT id FROM cammarket237.users WHERE phone=? AND role='buyer' LIMIT 1");
    $chk->execute([$phone]);
    if ($chk->fetch()) fail('Phone number already registered. Please login.');

    $hash  = password_hash($pass, PASSWORD_DEFAULT);
    $tok   = token();
    $exp   = date('Y-m-d H:i:s', strtotime('+'.SESSION_HOURS.' hours'));

    // Generate referral code + signup bonus
    $myRef       = strtoupper(substr(md5($phone . time()), 0, 8));
    $promoPoints = 10;
    $refPoints   = 0;
    $refUserId   = null;
    $refCode     = strtoupper(trim(p('referral_code') ?: ''));

    if ($refCode) {
        $refOwner = db()->prepare("SELECT id FROM cammarket237.users WHERE referral_code=? LIMIT 1");
        $refOwner->execute([$refCode]);
        $ro = $refOwner->fetch();
        if ($ro) {
            $refUserId   = $ro['id'];
            $promoPoints = 20;
            $refPoints   = 10;
            db()->prepare("UPDATE cammarket237.users SET referral_points=referral_points+5,
                referral_count=referral_count+1 WHERE id=?")->execute([$ro['id']]);
        }
    }

    $stmt = db()->prepare(
        "INSERT INTO cammarket237.users
         (full_name,phone,password_hash,role,region,town,phone_verified,
          session_token,session_expires_at,referral_code,promo_points,referral_points,
          recovery_pin_hash,pin_set_at,referred_by,created_at)
         VALUES (?,?,?,'buyer',?,?,false,?,?,?,?,?,?,NOW(),?,NOW())
         RETURNING id,full_name,phone,role,region,town,session_token,referral_code,promo_points"
    );
    $stmt->execute([$name,$phone,$hash,$region,$town,$tok,$exp,$myRef,$promoPoints,$refPoints,$pinHash,$refUserId]);
    $user = $stmt->fetch();

    if ($refUserId) {
        try {
            db()->prepare("INSERT INTO cammarket237.referrals
                (referrer_id,referee_id,referral_code_used,created_at)
                VALUES (?,?,?,NOW())
                ON CONFLICT (referrer_id,referee_id) DO NOTHING")->execute([$refUserId,$user['id'],$refCode]);
        } catch(Exception $e) {}
        // 150 FCFA instant cash reward for buyer referral
        try {
            db()->prepare(
                "INSERT INTO cammarket237.referral_rewards
                 (referrer_id,referee_id,referee_role,reward_fcfa,status,confirmed_at)
                 VALUES (?,?,'buyer',150,'confirmed',NOW())
                 ON CONFLICT (referee_id) DO NOTHING"
            )->execute([$refUserId, $user['id']]);
            db()->prepare(
                "UPDATE cammarket237.users SET wallet_balance=COALESCE(wallet_balance,0)+150 WHERE id=?"
            )->execute([$refUserId]);
            logWalletTx($refUserId, 150, 'referral_bonus', 'Referee signup bonus');
        } catch(Exception $e) {}
    }

    ok(['user'=>$user,'message'=>'Account created! Welcome to CamMarket237. Your promo code: '.$myRef]);
}


// ═══════════════════════════════════════════════════════════
// REGISTER SELLER
// ═══════════════════════════════════════════════════════════
if ($action === 'register_seller') {
    $name      = p('full_name');
    $phone     = p('phone');
    $pass      = p('password');
    $cpass     = p('confirm_password');
    $storeName = p('store_name');
    $region    = p('region');
    $town      = p('town');
    $address   = p('store_address');
    $physical  = p('physical_store') ?: 'No';
    $landmark  = p('landmark') ?: '';
    $desc      = p('description') ?: '';
    $lat       = p('latitude')  ? floatval(p('latitude'))  : null;
    $lng       = p('longitude') ? floatval(p('longitude')) : null;
    $refCode   = strtoupper(trim(p('referral_code') ?: ''));
    $pin       = trim(p('recovery_pin'));

    if (!$name||!$phone||!$pass||!$storeName||!$region||!$town)
        fail('Please fill all required fields.');
    if ($pass !== $cpass)   fail('Passwords do not match.');
    if (strlen($pass) < 6)  fail('Password must be at least 6 characters.');
    if (!preg_match('/^[0-9]{6}$/', $pin)) fail('Recovery PIN must be exactly 6 digits.');
    $weakPins = ['000000','111111','222222','333333','444444','555555','666666','777777','888888','999999','123456','654321','121212','112233','123123'];
    if (in_array($pin, $weakPins)) fail('Please choose a less obvious PIN.');
    $pinHash = password_hash($pin, PASSWORD_DEFAULT);

    // Check phone not already registered AS SELLER (allow same phone as buyer)
    // Check ALL phone variants to prevent duplicates
    $phoneVariants = normalizePhone($phone);
    $placeholders = implode(',', array_fill(0, count($phoneVariants), '?'));
    $chkStmt = db()->prepare("SELECT id FROM cammarket237.users WHERE phone IN ($placeholders) AND role='seller' LIMIT 1");
    $chkStmt->execute($phoneVariants);
    if ($chkStmt->fetch()) fail('Phone already registered as seller. Please login.');

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $tok  = token();
    $exp  = date('Y-m-d H:i:s', strtotime('+'.SESSION_HOURS.' hours'));

    // Generate referral code
    $myRef = strtoupper(substr(md5($phone . time()), 0, 8));

    // Bonus points for using referral code
    $promoPoints = 10; // signup bonus
    $refPoints   = 0;
    $refUserId   = null;

    if ($refCode) {
        $refOwner = db()->prepare(
            "SELECT id FROM cammarket237.users WHERE referral_code=? LIMIT 1"
        );
        $refOwner->execute([$refCode]);
        $ro = $refOwner->fetch();
        if ($ro) {
            $refUserId   = $ro['id'];
            $promoPoints = 20; // bonus for using referral
            $refPoints   = 10;
            // Give referrer +5 points
            db()->prepare(
                "UPDATE cammarket237.users SET referral_points=referral_points+5,
                 referral_count=referral_count+1 WHERE id=?"
            )->execute([$ro['id']]);
        }
    }

    db()->beginTransaction();
    try {
        // Create user
        $userStmt = db()->prepare(
            "INSERT INTO cammarket237.users
             (full_name,phone,password_hash,role,region,town,
              session_token,session_expires_at,referral_code,
              promo_points,referral_points,
              recovery_pin_hash,pin_set_at,referred_by,created_at)
             VALUES (?,?,?,'seller',?,?,?,?,?,?,?,?,NOW(),?,NOW())
             RETURNING id,full_name,phone,role,region,town,
                       session_token,referral_code"
        );
        $userStmt->execute([
            $name,$phone,$hash,$region,$town,
            $tok,$exp,$myRef,$promoPoints,$refPoints,$pinHash,$refUserId
        ]);
        $user = $userStmt->fetch();
        $uid  = $user['id'];

        // Create store
        $storeStmt = db()->prepare(
            "INSERT INTO cammarket237.stores
             (user_id,store_name,region,area_quarter,landmark,
              whatsapp,latitude,longitude,created_at)
             VALUES (?,?,?,?,?,?,?,?,NOW())
             RETURNING id,store_name,whatsapp,latitude,longitude,region,area_quarter,landmark"
        );
        $storeStmt->execute([
            $uid,$storeName,$region,$town,$landmark,
            $phone,$lat,$lng
        ]);
        $store = $storeStmt->fetch();

        // Record referral
        if ($refUserId) {
            db()->prepare(
                "INSERT INTO cammarket237.referrals
                 (referrer_id,referee_id,referral_code_used,created_at)
                 VALUES (?,?,?,NOW())
                 ON CONFLICT (referrer_id,referee_id) DO NOTHING"
            )->execute([$refUserId,$uid,$refCode]);
            // 200 FCFA pending reward for seller referral (unlocks at 10 listings)
            db()->prepare(
                "INSERT INTO cammarket237.referral_rewards
                 (referrer_id,referee_id,referee_role,reward_fcfa,status)
                 VALUES (?,?,'seller',200,'pending')
                 ON CONFLICT (referee_id) DO NOTHING"
            )->execute([$refUserId, $uid]);
        }

        db()->commit();

        $storeArr = [
            'id'          => $store['id'],
            'store_name'  => $store['store_name'],
            'whatsapp'    => $store['whatsapp'],
            'latitude'    => $store['latitude'],
            'longitude'   => $store['longitude'],
            'region'      => $store['region'],
            'area_quarter'=> $store['area_quarter'],
            'landmark'    => $store['landmark'],
        ];

        // Give 30 free streaming minutes to first 200 sellers
        try {
            $sellerCount = q1("SELECT COUNT(*) AS n FROM cammarket237.users WHERE role='seller'");
            if (intval($sellerCount['n']) <= 200) {
                $bal = q1("SELECT id FROM cammarket237.stream_balance WHERE seller_id=?", [$user['id']]);
                if (!$bal) {
                    db()->prepare("INSERT INTO cammarket237.stream_balance (seller_id,minutes_available,first_purchase_bonus_given) VALUES (?,30,false)")->execute([$user['id']]);
                } else {
                    db()->prepare("UPDATE cammarket237.stream_balance SET minutes_available=minutes_available+30 WHERE seller_id=?")->execute([$user['id']]);
                }
                db()->prepare("INSERT INTO cammarket237.stream_transactions (seller_id,transaction_type,minutes_added,amount_fcfa,note) VALUES (?,'weekly_free',30,0,'Welcome bonus - 30 free mins (first 200 sellers)')")->execute([$user['id']]);
            }
        } catch(Exception $ex) {}

        ok([
            'user'    => $user,
            'store'   => $storeArr,
            'message' => 'Seller account created! Welcome ' . $name . '. You got 30 FREE streaming minutes!',
        ]);

        // Handle referral promo code
        $promoCode = strtoupper(trim(p('promo_code')));
        $promoBonus = false;
        if ($promoCode) {
            try {
                $referrer = q1("SELECT id FROM cammarket237.users WHERE referral_code=? LIMIT 1", [$promoCode]);
                if ($referrer && $referrer['id'] !== $userId) {
                    db()->prepare("UPDATE cammarket237.users SET referred_by=? WHERE id=?")->execute([$referrer['id'], $userId]);
                    // Referrer gets 5 points for seller referral
                    db()->prepare("UPDATE cammarket237.users SET promo_points=COALESCE(promo_points,0)+5 WHERE id=?")->execute([$referrer['id']]);
                    $promoBonus = true;
                }
            } catch(Exception $ex) {}
        }

        // Handle referral promo code
        $promoCode = strtoupper(trim(p('promo_code')));
        $promoBonus = false;
        if ($promoCode) {
            try {
                $referrer = q1("SELECT id FROM cammarket237.users WHERE referral_code=? LIMIT 1", [$promoCode]);
                if ($referrer && $referrer['id'] !== $userId) {
                    db()->prepare("UPDATE cammarket237.users SET referred_by=? WHERE id=?")->execute([$referrer['id'], $userId]);
                    // Referrer gets 5 points for seller referral
                    db()->prepare("UPDATE cammarket237.users SET promo_points=COALESCE(promo_points,0)+5 WHERE id=?")->execute([$referrer['id']]);
                    $promoBonus = true;
                }
            } catch(Exception $ex) {}
        }

        // Give 30 free streaming minutes to first 200 sellers
        try {
            $sellerCount = q1("SELECT COUNT(*) AS n FROM cammarket237.users WHERE role='seller'");
            if (intval($sellerCount['n']) <= 200) {
                $bal = q1("SELECT id FROM cammarket237.stream_balance WHERE seller_id=?", [$userId]);
                if (!$bal) {
                    db()->prepare("INSERT INTO cammarket237.stream_balance (seller_id,minutes_available,first_purchase_bonus_given) VALUES (?,30,false)")->execute([$userId]);
                } else {
                    db()->prepare("UPDATE cammarket237.stream_balance SET minutes_available=minutes_available+30 WHERE seller_id=?")->execute([$userId]);
                }
                db()->prepare("INSERT INTO cammarket237.stream_transactions (seller_id,transaction_type,minutes_added,amount_fcfa,note) VALUES (?,'weekly_free',30,0,'Welcome bonus - 30 free mins (first 200 sellers)')")->execute([$userId]);
            }
        } catch(Exception $ex) {}
    } catch(Exception $e) {
        db()->rollBack();
        fail('Registration failed: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════
// SEND OTP  (otp_tokens uses phone column)
// ═══════════════════════════════════════════════════════════
if ($action === 'send_otp') {
    $phone = p('phone');
    if (!$phone) fail('Phone number required.');

    // Rate limit: max 3 OTP requests per phone per 10 minutes
    $rl = checkRateLimit($phone, 'send_otp', 3, 600);
    if (!$rl['allowed']) fail('Too many OTP requests. Please wait ' . ($rl['wait_minutes'] ?? 1) . ' minute(s) before trying again.');

    // Check if phone exists in DB
    $userCheck = db()->prepare("SELECT id, full_name FROM cammarket237.users WHERE phone=? LIMIT 1");
    $userCheck->execute([$phone]);
    $existingUser = $userCheck->fetch();

    // Get WhatsApp from stores table if seller
    $storeCheck = db()->prepare("SELECT s.whatsapp FROM cammarket237.stores s JOIN cammarket237.users u ON s.user_id=u.id WHERE u.phone=? LIMIT 1");
    $storeCheck->execute([$phone]);
    $store = $storeCheck->fetch();

    // WhatsApp number: store whatsapp → or phone itself
    $waNumber = null;
    if ($store && !empty($store['whatsapp'])) {
        $waNumber = preg_replace('/[^0-9]/', '', $store['whatsapp']);
    } else {
        $waNumber = preg_replace('/[^0-9]/', '', $phone);
    }
    if ($waNumber && !str_starts_with($waNumber, '237') && strlen($waNumber) <= 9) {
        $waNumber = '237' . $waNumber;
    }

    $otp = str_pad(rand(100000,999999), 6, '0', STR_PAD_LEFT);
    $exp = date('Y-m-d H:i:s', strtotime('+'.OTP_MINUTES.' minutes'));

    // Delete old OTPs for this phone
    db()->prepare("DELETE FROM cammarket237.otp_tokens WHERE phone=?")->execute([$phone]);

    // Insert new OTP
    db()->prepare(
        "INSERT INTO cammarket237.otp_tokens (phone,token,expires_at,used,created_at)
         VALUES (?,?,?,false,NOW())"
    )->execute([$phone,$otp,$exp]);

    // Build WhatsApp message with security warning
    $appName = 'CamMarket237';
    $waMessage = urlencode(
        "🔐 *{$appName} Security Code*\n\n" .
        "Your verification code is:\n\n" .
        "*{$otp}*\n\n" .
        "⏰ Valid for 10 minutes.\n\n" .
        "🚫 *Do NOT share this code with anyone.*\n" .
        "CamMarket237 will never ask for your code.\n\n" .
        "If you did not request this, please ignore."
    );

    $waLink = "https://wa.me/{$waNumber}?text={$waMessage}";

    // Check if user exists
    $exists = $existingUser ? true : false;

    $resp = [
        'wa_link'   => $waLink,
        'wa_number' => $waNumber,
        'exists'    => $exists,
        'message'   => 'Code ready - open WhatsApp to send'
    ];
    // Only expose OTP code in local Docker environment
    if (file_exists('/.dockerenv')) $resp['dev_code'] = $otp;
    ok($resp);
}

// ═══════════════════════════════════════════════════════════
// VERIFY OTP
// ═══════════════════════════════════════════════════════════
if ($action === 'verify_otp') {
    $phone = p('phone');
    $otp   = p('otp');
    if (!$phone||!$otp) fail('Phone and code required.');

    // Check OTP
    $s = db()->prepare(
        "SELECT id FROM cammarket237.otp_tokens
         WHERE phone=? AND token=? AND expires_at>NOW() AND used=false
         ORDER BY created_at DESC LIMIT 1"
    );
    $s->execute([$phone,$otp]);
    $row = $s->fetch();
    if (!$row) fail('Invalid or expired code. Please try again.');

    // Mark OTP used
    db()->prepare("UPDATE cammarket237.otp_tokens SET used=true WHERE id=?")->execute([$row['id']]);

    // Get user
    $us = db()->prepare("SELECT * FROM cammarket237.users WHERE phone=? LIMIT 1");
    $us->execute([$phone]);
    $user = $us->fetch();
    if(!$user){
      // New phone - grant 24hr read-only guest access
      echo json_encode(['success'=>true,'guest'=>true,'phone'=>$phone,
        'user'=>['id'=>0,'full_name'=>'Guest','phone'=>$phone,'role'=>'guest',
          'region'=>'','town'=>'','session_token'=>'guest_'.md5($phone.time())]]);
      exit;
    }

    // Create session
    $tok = token();
    $exp = date('Y-m-d H:i:s', strtotime('+'.SESSION_HOURS.' hours'));
    db()->prepare(
        "UPDATE cammarket237.users
         SET session_token=?,session_expires_at=?,phone_verified=true,last_login_at=NOW()
         WHERE id=?"
    )->execute([$tok,$exp,$user['id']]);

    ok(['user'=>[
        'id'            => $user['id'],
        'full_name'     => $user['full_name'],
        'phone'         => $user['phone'],
        'role'          => $user['role'],
        'region'        => $user['region'] ?? '',
        'town'          => $user['town'] ?? $user['area_quarter'] ?? '',
        'session_token' => $tok,
    ]]);
}

// ═══════════════════════════════════════════════════════════
// SELLER LOGIN
// ═══════════════════════════════════════════════════════════
if ($action === 'seller_login') {
    $phone = trim(p('phone'));
    $pass  = p('password');
    $ip=getClientIP(); $rl=checkRateLimit($ip.'_'.$phone,'seller_login',5,300); if(!$rl['allowed']) fail('Too many login attempts. Wait '.$rl['wait_minutes'].' min(s).');
    if (!$phone||!$pass) fail('Phone and password required.');
    $user = findUserByPhone($phone, 'seller');

    if (!$user)                       fail('No account found. Please register.');
    if ($user['role'] !== 'seller')   fail('This is not a seller account.');
    if (!password_verify($pass,$user['password_hash'])) {
        // Track failed attempts
        db()->prepare("UPDATE cammarket237.users SET failed_attempts=COALESCE(failed_attempts,0)+1 WHERE id=?")
            ->execute([$user['id']]);
        fail('Incorrect password.');
    }
    if (!empty($user['locked_until']) && strtotime($user['locked_until'])>time())
        fail('Account locked. Try again later.');

    // Create session
    $tok = token();
    $exp = date('Y-m-d H:i:s', strtotime('+'.SESSION_HOURS.' hours'));
    db()->prepare(
        "UPDATE cammarket237.users
         SET session_token=?,session_expires_at=?,last_login_at=NOW(),failed_attempts=0
         WHERE id=?"
    )->execute([$tok,$exp,$user['id']]);

    // Get store
    $st = db()->prepare("SELECT * FROM cammarket237.stores WHERE user_id=? LIMIT 1");
    $st->execute([$user['id']]);
    $store = $st->fetch();
    if (!$store) fail('Store not found. Contact support.');

    ok([
        'user' => [
            'id'             => $user['id'],
            'full_name'      => $user['full_name'],
            'phone'          => $user['phone'],
            'role'           => $user['role'],
            'region'         => $user['region'] ?? $store['region'] ?? '',
            'town'           => $user['town'] ?? $user['area_quarter'] ?? $store['area_quarter'] ?? '',
            'session_token'  => $tok,
            'wallet_balance' => intval($user['wallet_balance'] ?? 0),
            'referral_code'  => $user['referral_code'] ?? '',
        ],
        'store'   => $store,
        'has_pin' => !empty($user['recovery_pin_hash']),
    ]);
}

// ═══════════════════════════════════════════════════════════
// GET LISTINGS  (real DB — sorted by proximity)
// ═══════════════════════════════════════════════════════════
if ($action === 'get_listings') {
    $q      = g('q');
    $town   = g('town');
    $region = g('region');
    $range  = g('range') ?: 'national';
    $lat    = g('lat');
    $lng    = g('lng');

    $where  = ["l.status='active' AND COALESCE(l.moderation_status,'approved')='approved' AND COALESCE(s.is_active,true)=true AND COALESCE(s.suspended,false)=false"];
    $params = [];

    // Store filter (for "more from seller")
    $storeFilter = intval(g('store_id') ?: 0);
    if ($storeFilter) { $where[] = "l.store_id = :sid"; $params[':sid'] = $storeFilter; }

    // Exclude current item
    $excludeId = intval(g('exclude') ?: 0);
    if ($excludeId) { $where[] = "l.id != :excl"; $params[':excl'] = $excludeId; }

    if ($q) {
        $where[]      = "(l.title ILIKE :q OR l.category ILIKE :q OR l.description ILIKE :q)";
        $params[':q'] = '%'.$q.'%';
    }
    // Category filter
    $cat = g('cat');
    if ($cat) { $where[] = "l.category ILIKE :cat"; $params[':cat'] = '%'.$cat.'%'; }
    // Price range filter
    $pmin = g('pmin'); $pmax = g('pmax');
    if ($pmin && is_numeric($pmin)) { $where[] = "l.price >= :pmin"; $params[':pmin'] = $pmin; }
    if ($pmax && is_numeric($pmax)) { $where[] = "l.price <= :pmax"; $params[':pmax'] = $pmax; }
    // Condition filter
    $cond = g('cond');
    if ($cond) { $where[] = "l.condition = :cond"; $params[':cond'] = $cond; }
    if ($range==='town' && $town) {
        $where[]         = "l.town=:town";
        $params[':town'] = $town;
    } elseif ($range==='region' && $region) {
        $where[]           = "s.region=:region";
        $params[':region'] = $region;
    }

    $w = implode(' AND ',$where);

    // GPS distance ordering
    if ($lat && $lng) {
        $distCol = ",(6371*acos(GREATEST(-1,LEAST(1,cos(radians(:lat))*cos(radians(s.latitude::float))
                    *cos(radians(s.longitude::float)-radians(:lng))
                    +sin(radians(:lat2))*sin(radians(s.latitude::float)))))) AS dist_km";
        $orderBy = "dist_km ASC, l.created_at DESC";
        $params[':lat']=$lat; $params[':lng']=$lng; $params[':lat2']=$lat;
    } else {
        $distCol = "";
        $orderBy = "CASE WHEN l.town=:bt THEN 0 WHEN s.region=:br THEN 1 ELSE 2 END, l.created_at DESC";
        $params[':bt']=$town; $params[':br']=$region;
    }

    $sql = "SELECT l.id,l.title,l.price,l.price_type,l.category,l.town,l.condition,
                   COALESCE(l.stock_status,'in_stock') AS stock_status,
                   COALESCE(l.quantity_available,1) AS quantity_available,
                   COALESCE(l.is_sold,false) AS is_sold,
                   COALESCE(l.original_price,l.price) AS original_price,
                   COALESCE(l.price_drop_active,false) AS price_drop_active,
                   l.description,l.bulk_available,
                   l.bulk_discount_note,l.views,l.created_at,l.listing_type,
                   s.id AS store_id,s.store_name,s.whatsapp,s.latitude,s.longitude,
                   s.region,s.rating,s.trust_score,s.landmark,s.area_quarter,
            (SELECT media_url FROM cammarket237.listing_media
             WHERE listing_id=l.id AND media_role IN ('main','main_image') LIMIT 1) AS main_photo,
            (SELECT media_url FROM cammarket237.listing_media
             WHERE listing_id=l.id AND media_role IN ('secondary','secondary_image','extra_image') LIMIT 1) AS photo2,
            (SELECT media_url FROM cammarket237.listing_media
             WHERE listing_id=l.id AND media_role IN ('360view','video_360','video') LIMIT 1) AS video360
            $distCol
            FROM cammarket237.listings l
            LEFT JOIN cammarket237.stores s ON l.store_id=s.id
            WHERE $w
            ORDER BY $orderBy
            LIMIT 60";

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        ok(['listings'=>$stmt->fetchAll()]);
    } catch(PDOException $e) { fail($e->getMessage()); }
}

// ═══════════════════════════════════════════════════════════
// GET SELLER'S OWN LISTINGS
// ═══════════════════════════════════════════════════════════
if ($action === 'seller_listings') {
    $user = authUser();
    if (!$user) fail('Not authenticated.');
    $store_id = intval(g('store_id') ?: p('store_id'));
    $stmt = db()->prepare(
        "SELECT l.id,l.title,l.price,l.category,l.town,l.status,
                l.views,l.created_at,l.moderation_status,l.price_type,
         (SELECT media_url FROM cammarket237.listing_media
          WHERE listing_id=l.id AND media_role IN ('main','main_image') LIMIT 1) AS main_photo
         FROM cammarket237.listings l
         WHERE l.store_id=?
         ORDER BY l.created_at DESC LIMIT 50"
    );
    $stmt->execute([$store_id]);
    ok(['listings'=>$stmt->fetchAll()]);
}

// ═══════════════════════════════════════════════════════════
// POST LISTING  (with AI photo verification)
// ═══════════════════════════════════════════════════════════
if ($action === 'post_listing') {
    $user = authUser();
    if (!$user) fail('Please login first.');
    if ($user['role'] !== 'seller') fail('Only sellers can post items.');

    foreach (['store_id','title','price','category','town'] as $f)
        if (empty($_POST[$f])) fail("Missing: $f");

    $title    = trim($_POST['title']       ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $category = trim($_POST['category']    ?? '');

    if (empty($_FILES['photo1']['name'])||empty($_FILES['photo2']['name']))
        fail('Please upload at least 2 photos (video optional).');

    // Save files
    function saveFile($file,$type) {
        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR,0755,true);
        $imgs = ['image/jpeg','image/png','image/webp'];
        $vids = ['video/mp4','video/webm','video/quicktime','video/mpeg'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if ($type==='photo'&&!in_array($realMime,$imgs)) return ['ok'=>false,'error'=>'Photos: JPG/PNG/WEBP only.'];
        if ($type==='video'&&!in_array($realMime,$vids)) return ['ok'=>false,'error'=>'Video: MP4/WEBM only.'];
        if ($type==='photo'&&$file['size']>8*1024*1024) return ['ok'=>false,'error'=>'Photo max 8MB.'];
        if ($type==='video'&&$file['size']>200*1024*1024) return ['ok'=>false,'error'=>'Video max 200MB.'];
        $ext  = strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
        $name = uniqid($type.'_',true).'.'.$ext;
        if (!move_uploaded_file($file['tmp_name'],UPLOAD_DIR.$name)) return ['ok'=>false,'error'=>'Upload failed.'];
        return ['ok'=>true,'path'=>UPLOAD_DIR.$name,'url'=>UPLOAD_URL.$name];
    }

    $p1 = saveFile($_FILES['photo1'],'photo'); if(!$p1['ok']) fail($p1['error']);
    $p2 = saveFile($_FILES['photo2'],'photo'); if(!$p2['ok']){@unlink($p1['path']);fail($p2['error']);}
    // Photo 3 - optional
    $p3 = ['ok'=>true,'url'=>null,'path'=>null];
    if(!empty($_FILES['photo3']['name'])){ $p3=saveFile($_FILES['photo3'],'photo'); if(!$p3['ok']){@unlink($p1['path']);@unlink($p2['path']);fail($p3['error']);} }
    // Video - optional
    $vd = ['ok'=>true,'url'=>null,'path'=>null];
    if(!empty($_FILES['video']['name'])){ $vd=saveFile($_FILES['video'],'video'); if(!$vd['ok']){@unlink($p1['path']);@unlink($p2['path']);if($p3['path'])@unlink($p3['path']);fail($vd['error']);} }

    // ── FULL VERIFICATION: Photos + Content Moderation ────
    // Checks: real photos, no AI-generated, no dangerous items
    // Zero API cost - runs locally using PHP GD only
    // Photo verification with error handling
    $ai = ['approved'=>true];
    try {
        $verResult = runFullVerification(
            $p1['path'], $p2['path'],
            !empty($p3['path']) ? $p3['path'] : null,
            trim($_POST['title'] ?? ''),
            trim($_POST['description'] ?? ''),
            trim($_POST['category'] ?? ''),
            intval($_POST['store_id'] ?? 0),
            $user['id']
        );
        $ai = $verResult;
    } catch(Throwable $verErr) {
        // Verification failed - log but allow upload (fail open)
        error_log('Verify error: ' . $verErr->getMessage());
        $ai = ['approved'=>true];
    }
    if(!$ai['approved']){
        @unlink($p1['path']); @unlink($p2['path']);
        if(!empty($p3['path'])) @unlink($p3['path']);
        if(!empty($vd['path'])) @unlink($vd['path']);
        $ok_response = ['success'=>false,'rejected'=>true,'reason'=>$ai['reason']];
        if(!empty($ai['flagged'])) $ok_response['flagged'] = true;
        echo json_encode($ok_response); exit;
    }

    // Save to DB
    try {
        $stmt = db()->prepare(
            "INSERT INTO cammarket237.listings
             (store_id,user_id,title,description,price,category,town,status,
              listing_type,price_type,quantity_available,bulk_available,
              bulk_discount_note,video_url,ai_status,moderation_status,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,'active',?,?,?,?,?,?,'approved','approved',NOW(),NOW())
             RETURNING id"
        );
        $stmt->execute([
            intval($_POST['store_id']), $user['id'],
            $title, $desc,
            intval($_POST['price']),
            $category,
            trim($_POST['town']),
            $_POST['listing_type'] ?? 'sale',
            $_POST['price_type']   ?? 'fixed',
            intval($_POST['quantity'] ?? 1),
            !empty($_POST['bulk_available']) ? 'true' : 'false',
            trim($_POST['bulk_note'] ?? ''),
            $vd['url'],
        ]);
        $lid = $stmt->fetch()['id'];

        // Save media
        $ms = db()->prepare(
            "INSERT INTO cammarket237.listing_media
             (listing_id,media_type,media_url,media_role,sort_order,created_at)
             VALUES (?,?,?,?,?,NOW())"
        );
        $ms->execute([$lid,'image',$p1['url'],'main_image',1]);
        $ms->execute([$lid,'image',$p2['url'],'extra_image',2]);
        if(!empty($p3['url'])) $ms->execute([$lid,'image',$p3['url'],'extra_image',3]);
        if(!empty($vd['url'])) $ms->execute([$lid,'video',$vd['url'],'video_360',4]);

        // Check if seller just hit 5 items → trigger store announcement
        $storeId = intval($_POST['store_id'] ?? 0);
        if ($storeId) {
            $itemCount = q1("SELECT COUNT(*) AS n FROM cammarket237.listings
                WHERE store_id=? AND status='active'", [$storeId]);
            if (intval($itemCount['n']) === 5) {
                $alreadyAnn = q1("SELECT id FROM cammarket237.store_announcements
                    WHERE store_id=? LIMIT 1", [$storeId]);
                if (!$alreadyAnn) {
                    db()->prepare("INSERT INTO cammarket237.store_announcements
                        (store_id, triggered_by_listing_id, created_at)
                        VALUES (?,?,NOW())")->execute([$storeId, $lid]);
                }
            }
        }

        // Check if seller just hit 10 listings → confirm pending referral reward
        $totalListings = q1(
            "SELECT COUNT(*) AS n FROM cammarket237.listings
             WHERE store_id IN (SELECT id FROM cammarket237.stores WHERE user_id=?)
             AND status != 'deleted'",
            [$user['id']]
        );
        if (intval($totalListings['n']) >= 10) {
            $pendingRewards = q(
                "SELECT * FROM cammarket237.referral_rewards
                 WHERE referee_id=? AND status='pending'",
                [$user['id']]
            );
            foreach ($pendingRewards as $rw) {
                db()->prepare(
                    "UPDATE cammarket237.referral_rewards
                     SET status='confirmed', confirmed_at=NOW() WHERE id=?"
                )->execute([$rw['id']]);
                db()->prepare(
                    "UPDATE cammarket237.users
                     SET wallet_balance=COALESCE(wallet_balance,0)+? WHERE id=?"
                )->execute([$rw['reward_fcfa'], $rw['referrer_id']]);
                logWalletTx($rw['referrer_id'], $rw['reward_fcfa'], 'referral_confirmed', 'Referral reward confirmed');
            }
        }

        ok(['listing_id'=>$lid,'message'=>'Item verified and posted live!']);
    } catch(PDOException $e){ fail($e->getMessage()); }
}

// ═══════════════════════════════════════════════════════════
// INCREMENT VIEWS
// ═══════════════════════════════════════════════════════════
if ($action==='view'){
    $id=intval(g('id'));
    if($id) db()->prepare("UPDATE cammarket237.listings SET views=COALESCE(views,0)+1 WHERE id=?")->execute([$id]);
    ok();
}


if($action === 'get_user_points'){
    $tok = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? g('token') ?? p('token');
    if(!$tok) fail('Not authenticated.');
    try {
        $s = db()->prepare("SELECT u.id,u.full_name,u.phone,u.role,u.referral_code,
            u.promo_points,u.referral_points,u.referral_count,
            (u.promo_points + u.referral_points) AS total_points,
            (SELECT COUNT(*) FROM cammarket237.referrals WHERE referrer_id=u.id AND reward_status='completed') AS successful_referrals
            FROM cammarket237.users u
            WHERE u.session_token=? AND u.session_expires_at>NOW() LIMIT 1");
        $s->execute([$tok]); $user = $s->fetch();
        if(!$user) fail('Session expired.');

        // Get referral history
        $rh = db()->prepare("SELECT r.created_at, u.full_name AS referee_name, r.promo_points_awarded
            FROM cammarket237.referrals r
            JOIN cammarket237.users u ON r.referee_id=u.id
            WHERE r.referrer_id=? ORDER BY r.created_at DESC LIMIT 10");
        $rh->execute([$user['id']]); $history = $rh->fetchAll();

        ok(['user'=>$user, 'referral_history'=>$history]);
    } catch(Exception $e){ fail($e->getMessage()); }
}


if($action === 'verify_otp_reset'){
    $phone = p('phone'); $otp = p('otp');
    if(!$phone||!$otp) fail('Phone and code required.');
    try {
        $s = db()->prepare("SELECT * FROM cammarket237.otp_tokens WHERE phone=? AND token=? AND used=false ORDER BY created_at DESC LIMIT 1");
        $s->execute([$phone,$otp]); $row = $s->fetch();
        if(!$row) fail('Invalid or expired code.');
        if(strtotime($row['expires_at']) < time()) fail('Code expired. Please request a new one.');
        // Check user exists
        $us = db()->prepare("SELECT id FROM cammarket237.users WHERE phone=? LIMIT 1");
        $us->execute([$phone]); $user = $us->fetch();
        if(!$user) fail('No account found for this phone.');
        // Mark used and issue reset token
        db()->prepare("UPDATE cammarket237.otp_tokens SET used=true WHERE id=?")->execute([$row['id']]);
        $reset_token = bin2hex(random_bytes(32));
        $exp = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        db()->prepare("UPDATE cammarket237.users SET session_token=?,session_expires_at=? WHERE id=?")->execute([$reset_token,$exp,$user['id']]);
        ok(['reset_token'=>$reset_token]);
    } catch(Exception $e){ fail($e->getMessage()); }
}

if($action === 'reset_password'){
    $token = p('reset_token'); $pass = p('password'); $cpass = p('confirm_password');
    if(!$token||!$pass) fail('Token and password required.');
    if(strlen($pass) < 6) fail('Password must be at least 6 characters.');
    if($pass !== $cpass) fail('Passwords do not match.');
    try {
        $us = db()->prepare("SELECT id FROM cammarket237.users WHERE session_token=? AND session_expires_at>NOW() LIMIT 1");
        $us->execute([$token]); $user = $us->fetch();
        if(!$user) fail('Reset token expired. Please start over.');
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        db()->prepare("UPDATE cammarket237.users SET password_hash=?,session_token=NULL,session_expires_at=NULL,password_changed_at=NOW() WHERE id=?")->execute([$hash,$user['id']]);
        ok(['message'=>'Password reset successfully!']);
    } catch(Exception $e){ fail($e->getMessage()); }
}


// ── GET REVIEWS ────────────────────────────────────

// ═══════════════════════════════════════════════════════════
// TRACK BUYER EVENT (search, view, click)
// ═══════════════════════════════════════════════════════════
if ($action === 'track_event') {
    $eventType  = p('event_type') ?: 'view';  // search|view|click|save
    $listingId  = p('listing_id') ? intval(p('listing_id')) : null;
    $category   = p('category')   ?: null;
    $query      = p('search_query') ?: null;
    $town       = p('town')       ?: null;
    $region     = p('region')     ?: null;
    $sessionId  = p('session_id') ?: null;
    $buyerId    = null;

    // Get buyer ID if logged in
    $tok = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    if ($tok) {
        $u = db()->prepare("SELECT id FROM cammarket237.users WHERE session_token=? AND session_expires_at>NOW() LIMIT 1");
        $u->execute([$tok]);
        $row = $u->fetch();
        if ($row) $buyerId = $row['id'];
    }

    db()->prepare("INSERT INTO cammarket237.buyer_events
        (buyer_id,session_id,event_type,listing_id,category,search_query,town,region,created_at)
        VALUES (?,?,?,?,?,?,?,?,NOW())")
        ->execute([$buyerId,$sessionId,$eventType,$listingId,$category,$query,$town,$region]);

    ok(['tracked' => true]);
}

// ═══════════════════════════════════════════════════════════
// SMART FEED - Amazon-style personalized sections
// ═══════════════════════════════════════════════════════════
if ($action === 'get_smart_feed') {
    $town      = p('town')    ?: '';
    $region    = p('region')  ?: '';
    $sessionId = p('session_id') ?: '';
    $buyerId   = null;
    $lat       = p('lat') ? floatval(p('lat')) : null;
    $lng       = p('lng') ? floatval(p('lng')) : null;

    $tok = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    if ($tok) {
        $u = db()->prepare("SELECT id FROM cammarket237.users WHERE session_token=? AND session_expires_at>NOW() LIMIT 1");
        $u->execute([$tok]);
        $row = $u->fetch();
        if ($row) $buyerId = $row['id'];
    }

    $mediaJoin = "(SELECT media_url FROM cammarket237.listing_media WHERE listing_id=l.id AND media_role='main_image' ORDER BY sort_order LIMIT 1)";
    $media2    = "(SELECT media_url FROM cammarket237.listing_media WHERE listing_id=l.id AND media_role='extra_image' ORDER BY sort_order LIMIT 1)";
    $media3    = "(SELECT media_url FROM cammarket237.listing_media WHERE listing_id=l.id AND media_role='extra_image' ORDER BY sort_order LIMIT 1 OFFSET 1)";
    $mediaVid  = "(SELECT media_url FROM cammarket237.listing_media WHERE listing_id=l.id AND media_role IN ('video_360','video','360view') ORDER BY sort_order LIMIT 1)";
    $baseSelect = "SELECT l.id,l.title,l.price,l.price_type,l.category,l.town,
                   l.condition,l.description,l.views,l.created_at,l.store_id,
                   s.store_name,s.whatsapp,s.latitude,s.longitude,s.rating,s.trust_score,
                   s.landmark,s.area_quarter,s.region,
                   $mediaJoin AS main_photo,
                   $media2 AS photo2,
                   $media3 AS photo3,
                   $mediaVid AS video360
                   FROM cammarket237.listings l
                   LEFT JOIN cammarket237.stores s ON s.id=l.store_id
                   WHERE l.status='active' AND l.moderation_status='approved'";

    $feed = [];

    // ── Section 1: Trending Near You ──────────────────────
    if ($town) {
        $trending = q("$baseSelect AND l.town=?
            ORDER BY l.views DESC, l.created_at DESC LIMIT 8", [$town]);
        if ($trending) {
            $feed[] = [
                'section'  => 'trending_near_you',
                'title'    => 'Trending Near You',
                'icon'     => 'fire',
                'listings' => $trending,
            ];
        }
    }

    // ── Section 2: Recommended For You ────────────────────
    // Based on buyer's recent searches and views
    $recommended = [];
    if ($buyerId || $sessionId) {
        $param = $buyerId ? $buyerId : $sessionId;
        $field = $buyerId ? 'buyer_id' : 'session_id';

        // Get top categories from recent events
        $topCats = q("SELECT category, COUNT(*) AS n
            FROM cammarket237.buyer_events
            WHERE $field=? AND category IS NOT NULL
            AND created_at > NOW() - INTERVAL '7 days'
            GROUP BY category ORDER BY n DESC LIMIT 3", [$param]);

        if ($topCats) {
            $cats = array_column($topCats, 'category');
            $placeholders = implode(',', array_fill(0, count($cats), '?'));
            $recommended = q("$baseSelect AND l.category IN ($placeholders)
                AND l.id NOT IN (
                    SELECT listing_id FROM cammarket237.buyer_events
                    WHERE $field=? AND listing_id IS NOT NULL
                )
                ORDER BY l.views DESC, l.created_at DESC LIMIT 8",
                array_merge($cats, [$param]));
        }

        // Fallback: get viewed categories
        if (empty($recommended)) {
            $viewedCats = q("SELECT DISTINCT l.category
                FROM cammarket237.buyer_events be
                JOIN cammarket237.listings l ON l.id=be.listing_id
                WHERE be.$field=? AND be.event_type='view'
                LIMIT 3", [$param]);
            if ($viewedCats) {
                $cats = array_column($viewedCats, 'category');
                $placeholders = implode(',', array_fill(0, count($cats), '?'));
                $recommended = q("$baseSelect AND l.category IN ($placeholders)
                    ORDER BY l.views DESC LIMIT 8", $cats);
            }
        }
    }
    if ($recommended) {
        $feed[] = [
            'section'  => 'recommended',
            'title'    => 'Recommended For You',
            'icon'     => 'star',
            'listings' => $recommended,
        ];
    }

    // ── Section 3: Recently Viewed ────────────────────────
    if ($buyerId || $sessionId) {
        $param = $buyerId ? $buyerId : $sessionId;
        $field = $buyerId ? 'buyer_id' : 'session_id';
        // Use subquery properly to get recent listings
        $recentIds = q("SELECT DISTINCT listing_id, MAX(created_at) AS last_seen
            FROM cammarket237.buyer_events
            WHERE $field=? AND listing_id IS NOT NULL AND event_type='view'
            GROUP BY listing_id ORDER BY last_seen DESC LIMIT 8", [$param]);
        if ($recentIds) {
            $ids = array_column($recentIds, 'listing_id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $recent = q("$baseSelect AND l.id IN ($placeholders) LIMIT 8", $ids);
            if ($recent) {
                $feed[] = [
                    'section'  => 'recently_viewed',
                    'title'    => 'Recently Viewed',
                    'icon'     => 'clock',
                    'listings' => $recent,
                ];
            }
        }
    }

    // ── Section 4: New Arrivals ────────────────────────────
    $newArrivals = q("$baseSelect
        AND l.created_at > NOW() - INTERVAL '7 days'
        ORDER BY l.created_at DESC LIMIT 8");
    if ($newArrivals) {
        $feed[] = [
            'section'  => 'new_arrivals',
            'title'    => 'New Arrivals',
            'icon'     => 'new',
            'listings' => $newArrivals,
        ];
    }

    // ── Section 5: Best Deals ──────────────────────────────
    $bestDeals = q("$baseSelect
        ORDER BY (l.views * 0.4 + (1.0/NULLIF(l.price,0)) * 1000000 * 0.6) DESC
        LIMIT 8");
    if ($bestDeals) {
        $feed[] = [
            'section'  => 'best_deals',
            'title'    => 'Best Deals',
            'icon'     => 'deal',
            'listings' => $bestDeals,
        ];
    }

    // ── Section 6: Most Popular ────────────────────────────
    $popular = q("$baseSelect
        ORDER BY l.views DESC, s.trust_score DESC LIMIT 8");
    if ($popular) {
        $feed[] = [
            'section'  => 'most_popular',
            'title'    => 'Most Popular',
            'icon'     => 'popular',
            'listings' => $popular,
        ];
    }

    ok(['feed' => $feed, 'sections' => count($feed)]);
}

// ═══════════════════════════════════════════════════════════
// TRENDING SEARCHES (for seller dashboard)
// ═══════════════════════════════════════════════════════════
if ($action === 'get_trending_searches') {
    $town   = p('town')   ?: null;
    $region = p('region') ?: null;

    $where = "WHERE event_type='search' AND search_query IS NOT NULL
              AND search_query != ''
              AND created_at > NOW() - INTERVAL '24 hours'";
    $params = [];
    if ($town) { $where .= " AND town=?"; $params[] = $town; }

    $searches = q("SELECT search_query, COUNT(*) AS count
        FROM cammarket237.buyer_events
        $where
        GROUP BY search_query
        ORDER BY count DESC LIMIT 10", $params);

    $categories = q("SELECT category, COUNT(*) AS count
        FROM cammarket237.buyer_events
        WHERE event_type IN ('view','click')
        AND category IS NOT NULL
        AND created_at > NOW() - INTERVAL '24 hours'
        GROUP BY category
        ORDER BY count DESC LIMIT 8");

    $hotTowns = q("SELECT town, COUNT(*) AS count
        FROM cammarket237.buyer_events
        WHERE town IS NOT NULL
        AND created_at > NOW() - INTERVAL '24 hours'
        GROUP BY town
        ORDER BY count DESC LIMIT 5");

    ok([
        'trending_searches'  => $searches,
        'hot_categories'     => $categories,
        'active_towns'       => $hotTowns,
    ]);
}



// ═══════════════════════════════════════════════════════════
// PRICE INTELLIGENCE - Compare market prices & suggest drops
// ═══════════════════════════════════════════════════════════

// ── Get market price comparison for a listing ─────────────
if ($action === 'get_price_intel') {
    $listingId = intval(p('listing_id'));
    if (!$listingId) fail('Missing listing_id');

    // Get current listing
    $listing = q1("SELECT l.*, s.town, s.region FROM cammarket237.listings l
        LEFT JOIN cammarket237.stores s ON s.id=l.store_id
        WHERE l.id=?", [$listingId]);
    if (!$listing) fail('Listing not found');

    // Find similar listings in same town/region
    $competitors = q("SELECT l.id, l.title, l.price, s.store_name, s.town,
        s.area_quarter, s.rating
        FROM cammarket237.listings l
        LEFT JOIN cammarket237.stores s ON s.id=l.store_id
        WHERE l.status='active'
        AND l.moderation_status='approved'
        AND l.id != ?
        AND l.category = ?
        AND (s.town = ? OR s.region = ?)
        AND l.price > 0
        ORDER BY ABS(l.price - ?) ASC
        LIMIT 10",
        [$listingId, $listing['category'],
         $listing['town'], $listing['region'],
         $listing['price']]);

    if (empty($competitors)) {
        ok(['has_competitors' => false,
            'message' => 'No similar items found in your area yet.']);
    }

    $prices   = array_column($competitors, 'price');
    $avgPrice = array_sum($prices) / count($prices);
    $minPrice = min($prices);
    $maxPrice = max($prices);

    // Calculate suggested price (5% below average, min 10% drop)
    $suggestedPrice = round($avgPrice * 0.95 / 100) * 100;
    $currentPrice   = floatval($listing['price']);
    $dropPct        = (($currentPrice - $suggestedPrice) / $currentPrice) * 100;

    $suggestion = null;
    if ($currentPrice > $avgPrice && $dropPct >= 10) {
        $suggestion = [
            'suggested_price' => $suggestedPrice,
            'savings_pct'     => round($dropPct),
            'reason'          => count($competitors) . ' sellers in ' .
                                 ($listing['town'] ?? 'your area') .
                                 ' sell similar items for an average of ' .
                                 number_format($avgPrice) . ' FCFA',
        ];
    }

    ok([
        'has_competitors'  => true,
        'current_price'    => $currentPrice,
        'avg_market_price' => round($avgPrice),
        'min_price'        => $minPrice,
        'max_price'        => $maxPrice,
        'competitor_count' => count($competitors),
        'competitors'      => array_slice($competitors, 0, 5),
        'suggestion'       => $suggestion,
        'position'         => $currentPrice <= $minPrice ? 'lowest' :
                             ($currentPrice <= $avgPrice ? 'competitive' : 'above_average'),
    ]);
}

// ── Apply price drop ───────────────────────────────────────
if ($action === 'apply_price_drop') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');

    $listingId = intval(p('listing_id'));
    $newPrice  = floatval(p('new_price'));
    if (!$listingId || !$newPrice) fail('Missing fields.');

    // Get current listing
    $listing = q1("SELECT l.*, s.user_id FROM cammarket237.listings l
        LEFT JOIN cammarket237.stores s ON s.id=l.store_id
        WHERE l.id=?", [$listingId]);
    if (!$listing) fail('Listing not found.');
    if ($listing['user_id'] != $user['id']) fail('Not your listing.');

    $oldPrice   = floatval($listing['price']);
    $discountPct = (($oldPrice - $newPrice) / $oldPrice) * 100;

    if ($discountPct < 10) fail('Price reduction must be at least 10%.');
    if ($newPrice <= 0)    fail('Invalid price.');

    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

    db()->beginTransaction();
    try {
        // Save original price if not already saved
        db()->prepare("UPDATE cammarket237.listings
            SET original_price = CASE WHEN original_price IS NULL THEN price ELSE original_price END,
                price = ?,
                price_drop_active = true,
                price_drop_expires = ?
            WHERE id=?")->execute([$newPrice, $expiresAt, $listingId]);

        // Record price drop
        $pdStmt = db()->prepare("INSERT INTO cammarket237.price_drops
            (listing_id, store_id, old_price, new_price, discount_pct, expires_at)
            VALUES (?,?,?,?,?,?) RETURNING id");
        $pdStmt->execute([$listingId, $listing['store_id'],
                          $oldPrice, $newPrice, $discountPct, $expiresAt]);
        $pd = $pdStmt->fetch();
        $pdId = $pd['id'];

        // Notify ALL buyers
        $buyers = q("SELECT id FROM cammarket237.users WHERE role='buyer'");
        $notifStmt = db()->prepare("INSERT INTO cammarket237.price_drop_notifications
            (buyer_id, listing_id, price_drop_id) VALUES (?,?,?)");
        foreach ($buyers as $b) {
            $notifStmt->execute([$b['id'], $listingId, $pdId]);
        }

        // Update suggestion status if exists
        db()->prepare("UPDATE cammarket237.price_suggestions
            SET status='accepted' WHERE listing_id=? AND status='pending'"
        )->execute([$listingId]);

        db()->commit();

        ok([
            'message'       => 'Price drop applied! ' . count($buyers) . ' buyers notified.',
            'old_price'     => $oldPrice,
            'new_price'     => $newPrice,
            'discount_pct'  => round($discountPct),
            'expires_at'    => $expiresAt,
            'buyers_notified' => count($buyers),
        ]);
    } catch(Exception $e) {
        db()->rollBack();
        fail($e->getMessage());
    }
}

// ── Get price drop notifications for buyer ─────────────────
if ($action === 'get_price_notifications') {
    $user = authUser();
    if (!$user) fail('Login required.');

    $notifs = q("SELECT pdn.*, l.title, l.price AS current_price,
        l.original_price, l.price_drop_expires,
        pd.old_price, pd.new_price, pd.discount_pct,
        s.store_name, s.town,
        lm.media_url AS photo
        FROM cammarket237.price_drop_notifications pdn
        JOIN cammarket237.listings l ON l.id=pdn.listing_id
        JOIN cammarket237.price_drops pd ON pd.id=pdn.price_drop_id
        LEFT JOIN cammarket237.stores s ON s.id=l.store_id
        LEFT JOIN cammarket237.listing_media lm ON lm.listing_id=l.id AND lm.media_role='main_image'
        WHERE pdn.buyer_id=?
        AND l.price_drop_active=true
        AND l.price_drop_expires > NOW()
        ORDER BY pdn.created_at DESC
        LIMIT 20", [$user['id']]);

    // Mark as seen
    db()->prepare("UPDATE cammarket237.price_drop_notifications
        SET seen=true WHERE buyer_id=?"
    )->execute([$user['id']]);

    $unseenCount = q1("SELECT COUNT(*) AS n FROM cammarket237.price_drop_notifications
        WHERE buyer_id=?", [$user['id']]);

    ok(['notifications' => $notifs, 'unseen' => intval($unseenCount['n'])]);
}

// ── Get unseen notification count ─────────────────────────
if ($action === 'get_notif_count') {
    $user = authUser();
    if (!$user) ok(['count' => 0]);
    $cnt = q1("SELECT COUNT(*) AS n FROM cammarket237.price_drop_notifications
        WHERE buyer_id=?", [$user['id']]);
    ok(['count' => intval($cnt['n'])]);
}

// ── Auto-suggest price drops (called by cron or on dashboard load)
if ($action === 'get_price_suggestions') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');

    // Find seller listings that are above market average
    $suggestions = q("SELECT l.id, l.title, l.price, l.category,
        s.town, s.region,
        COUNT(l2.id) AS competitor_count,
        AVG(l2.price) AS avg_market_price,
        MIN(l2.price) AS min_market_price
        FROM cammarket237.listings l
        LEFT JOIN cammarket237.stores s ON s.id=l.store_id
        LEFT JOIN cammarket237.listings l2 ON
            l2.category=l.category AND
            l2.id != l.id AND
            l2.status='active' AND
            l2.moderation_status='approved'
        LEFT JOIN cammarket237.stores s2 ON s2.id=l2.store_id AND
            (s2.town=s.town OR s2.region=s.region)
        WHERE s.user_id=?
        AND l.status='active'
        AND l.price_drop_active=false
        GROUP BY l.id, l.title, l.price, l.category, s.town, s.region
        HAVING COUNT(l2.id) >= 2
        AND l.price > AVG(l2.price) * 1.10
        ORDER BY (l.price - AVG(l2.price)) DESC
        LIMIT 5", [$user['id']]);

    $result = [];
    foreach ($suggestions as $s) {
        $suggested = round($s['avg_market_price'] * 0.95 / 100) * 100;
        $dropPct   = (($s['price'] - $suggested) / $s['price']) * 100;
        if ($dropPct >= 10) {
            $result[] = [
                'listing_id'       => $s['id'],
                'title'            => $s['title'],
                'current_price'    => $s['price'],
                'suggested_price'  => $suggested,
                'avg_market_price' => round($s['avg_market_price']),
                'min_market_price' => $s['min_market_price'],
                'competitor_count' => $s['competitor_count'],
                'discount_pct'     => round($dropPct),
                'town'             => $s['town'],
                'reason'           => $s['competitor_count'] . ' similar items in ' .
                                     ($s['town'] ?? 'your area') .
                                     ' average ' . number_format($s['avg_market_price']) . ' FCFA',
            ];
        }
    }

    ok(['suggestions' => $result, 'count' => count($result)]);
}



// ═══════════════════════════════════════════════════════════
// NEW STORE ANNOUNCEMENTS
// Triggered when seller posts their 5th item
// ═══════════════════════════════════════════════════════════

if ($action === 'get_store_announcements') {
    $user = authUser();
    if (!$user) fail('Login required.');

    $notifs = q("SELECT san.*, st.store_name, st.region, st.area_quarter,
        st.landmark, st.rating, st.whatsapp,
        u.full_name AS seller_name,
        (SELECT COUNT(*) FROM cammarket237.listings l WHERE l.store_id=san.store_id AND l.status='active') AS item_count,
        (SELECT COUNT(*) FROM cammarket237.followers f JOIN cammarket237.stores s2 ON s2.user_id=f.following_id WHERE s2.id=san.store_id) AS follower_count,
        (SELECT json_agg(sub.media_url) FROM (
            SELECT lm.media_url FROM cammarket237.listing_media lm
            JOIN cammarket237.listings l ON l.id=lm.listing_id
            WHERE l.store_id=san.store_id AND lm.media_role='main_image'
            ORDER BY l.created_at DESC LIMIT 3
        ) sub) AS preview_photos,
        (SELECT string_agg(DISTINCT l.category, ', ') FROM cammarket237.listings l
         WHERE l.store_id=san.store_id AND l.status='active') AS categories
        FROM cammarket237.store_announcements san
        JOIN cammarket237.stores st ON st.id=san.store_id
        JOIN cammarket237.users u ON u.id=st.user_id
        LEFT JOIN cammarket237.store_announcement_seen sas ON sas.announcement_id=san.id AND sas.user_id=?
        WHERE sas.id IS NULL
        AND san.created_at > NOW() - INTERVAL '30 days'
        ORDER BY san.created_at DESC
        LIMIT 20", [$user['id']]);

    // Mark all as seen
    foreach ($notifs as $n) {
        try {
            db()->prepare("INSERT INTO cammarket237.store_announcement_seen
                (announcement_id, user_id) VALUES (?,?)
                ON CONFLICT DO NOTHING")->execute([$n['id'], $user['id']]);
        } catch(Exception $e) {}
    }

    ok(['announcements' => $notifs, 'count' => count($notifs)]);
}

if ($action === 'get_announcement_count') {
    $user = authUser();
    if (!$user) ok(['count' => 0]);
    $cnt = q1("SELECT COUNT(*) AS n FROM cammarket237.store_announcements san
        LEFT JOIN cammarket237.store_announcement_seen sas
            ON sas.announcement_id=san.id AND sas.user_id=?
        WHERE sas.id IS NULL
        AND san.created_at > NOW() - INTERVAL '30 days'", [$user['id']]);
    ok(['count' => intval($cnt['n'])]);
}



// ═══════════════════════════════════════════════════════════
// SHOPPING CART
// ═══════════════════════════════════════════════════════════

if ($action === 'add_to_cart') {
    $user = authUser();
    if (!$user || $user['role'] !== 'buyer') fail('Buyers only.');
    $listingId = intval(p('listing_id'));
    if (!$listingId) fail('Missing listing_id.');
    try {
        db()->prepare("INSERT INTO cammarket237.cart_items
            (buyer_id, listing_id) VALUES (?,?)
            ON CONFLICT (buyer_id, listing_id) DO NOTHING")
            ->execute([$user['id'], $listingId]);
        $count = q1("SELECT COUNT(*) AS n FROM cammarket237.cart_items WHERE buyer_id=?", [$user['id']]);
        ok(['message' => 'Added to cart!', 'cart_count' => intval($count['n'])]);
    } catch(Exception $e) { fail($e->getMessage()); }
}

if ($action === 'remove_from_cart') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $listingId = intval(p('listing_id'));
    db()->prepare("DELETE FROM cammarket237.cart_items WHERE buyer_id=? AND listing_id=?")
        ->execute([$user['id'], $listingId]);
    $count = q1("SELECT COUNT(*) AS n FROM cammarket237.cart_items WHERE buyer_id=?", [$user['id']]);
    ok(['message' => 'Removed from cart.', 'cart_count' => intval($count['n'])]);
}

if ($action === 'get_cart') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $items = q("SELECT ci.id AS cart_id, ci.added_at,
        l.id, l.title, l.price, l.original_price, l.category,
        l.town, l.description, l.is_sold, l.price_drop_active, l.condition,
        s.store_name, s.whatsapp, s.region, s.rating,
        lm.media_url AS photo
        FROM cammarket237.cart_items ci
        JOIN cammarket237.listings l ON l.id=ci.listing_id
        LEFT JOIN cammarket237.stores s ON s.id=l.store_id
        LEFT JOIN cammarket237.listing_media lm ON lm.listing_id=l.id AND lm.media_role='main_image'
        WHERE ci.buyer_id=?
        ORDER BY ci.added_at DESC", [$user['id']]);
    $count = count($items);
    ok(['items' => $items, 'count' => $count]);
}

if ($action === 'get_cart_count') {
    $user = authUser();
    if (!$user) ok(['count' => 0]);
    $cnt = q1("SELECT COUNT(*) AS n FROM cammarket237.cart_items WHERE buyer_id=?", [$user['id']]);
    ok(['count' => intval($cnt['n'])]);
}

if ($action === 'get_cart_notifications') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $notifs = q("SELECT cn.*, l.title, l.price, l.is_sold,
        s.store_name, lm.media_url AS photo
        FROM cammarket237.cart_notifications cn
        JOIN cammarket237.listings l ON l.id=cn.listing_id
        LEFT JOIN cammarket237.stores s ON s.id=l.store_id
        LEFT JOIN cammarket237.listing_media lm ON lm.listing_id=l.id AND lm.media_role='main_image'
        WHERE cn.buyer_id=? AND cn.seen=false
        ORDER BY cn.created_at DESC LIMIT 20", [$user['id']]);
    db()->prepare("UPDATE cammarket237.cart_notifications SET seen=true WHERE buyer_id=?")
        ->execute([$user['id']]);
    $unseenCount = q1("SELECT COUNT(*) AS n FROM cammarket237.cart_notifications
        WHERE buyer_id=?", [$user['id']]);
    ok(['notifications' => $notifs, 'unseen' => intval($unseenCount['n'])]);
}

// ═══════════════════════════════════════════════════════════
// MARK AS SOLD / RELIST
// ═══════════════════════════════════════════════════════════

if ($action === 'mark_sold') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');
    $listingId = intval(p('listing_id'));
    $soldPrice  = p('sold_price') ? floatval(p('sold_price')) : null;
    if (!$listingId) fail('Missing listing_id.');

    $listing = q1("SELECT l.*, s.user_id FROM cammarket237.listings l
        LEFT JOIN cammarket237.stores s ON s.id=l.store_id WHERE l.id=?", [$listingId]);
    if (!$listing || $listing['user_id'] != $user['id']) fail('Not your listing.');

    db()->beginTransaction();
    try {
        db()->prepare("UPDATE cammarket237.listings
            SET is_sold=true, sold_at=NOW(), sold_price=?, status='sold'
            WHERE id=?")->execute([$soldPrice ?: $listing['price'], $listingId]);

        db()->prepare("INSERT INTO cammarket237.sold_listings
            (listing_id, store_id, sold_price) VALUES (?,?,?)")
            ->execute([$listingId, $listing['store_id'], $soldPrice ?: $listing['price']]);

        // Notify ALL who interacted with this listing
        $buyers = q("SELECT DISTINCT buyer_id FROM (
            SELECT buyer_id FROM cammarket237.cart_items WHERE listing_id=?
            UNION
            SELECT buyer_id FROM cammarket237.price_drop_notifications WHERE listing_id=?
            UNION
            SELECT DISTINCT buyer_id FROM cammarket237.buyer_events
                WHERE listing_id=? AND event_type='view'
                AND created_at > NOW() - INTERVAL '7 days'
                AND buyer_id IS NOT NULL
        ) combined", [$listingId, $listingId, $listingId]);

        $notifStmt = db()->prepare("INSERT INTO cammarket237.cart_notifications
            (buyer_id, listing_id, notification_type, message) VALUES (?,?,?,?)");
        foreach ($buyers as $b) {
            if ($b['buyer_id']) {
                $notifStmt->execute([
                    $b['buyer_id'], $listingId, 'sold',
                    ($listing['title'] ?? 'Item') . ' has been sold! Contact the seller for similar items.'
                ]);
            }
        }

        db()->commit();
        ok(['message' => 'Item marked as sold! ' . count($buyers) . ' buyers notified.', 'notified' => count($buyers)]);
    } catch(Exception $e) { db()->rollBack(); fail($e->getMessage()); }
}

if ($action === 'relist_item') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');
    $listingId = intval(p('listing_id'));
    $newQty    = intval(p('quantity') ?? 1);
    $newPrice  = p('price') ? floatval(p('price')) : null;

    $listing = q1("SELECT l.*, s.user_id FROM cammarket237.listings l
        LEFT JOIN cammarket237.stores s ON s.id=l.store_id WHERE l.id=?", [$listingId]);
    if (!$listing || $listing['user_id'] != $user['id']) fail('Not your listing.');

    db()->prepare("UPDATE cammarket237.listings
        SET is_sold=false, sold_at=NULL, status='active',
            quantity_available=?,
            price=COALESCE(?,price),
            moderation_status='approved'
        WHERE id=?")->execute([$newQty, $newPrice, $listingId]);

    db()->prepare("UPDATE cammarket237.sold_listings
        SET relisted_at=NOW(), relist_count=relist_count+1
        WHERE listing_id=? AND relisted_at IS NULL")->execute([$listingId]);

    ok(['message' => 'Item relisted successfully!']);
}

// ═══════════════════════════════════════════════════════════
// EDIT LISTING
// ═══════════════════════════════════════════════════════════

if ($action === 'edit_listing') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');
    $listingId   = intval(p('listing_id'));
    $title       = p('title');
    $desc        = p('description');
    $price       = p('price') ? floatval(p('price')) : null;
    $category    = p('category');
    $priceType   = p('price_type');
    $condition   = p('condition');

    if (!$listingId) fail('Missing listing_id.');

    $listing = q1("SELECT l.*, s.user_id, l.price AS old_price FROM cammarket237.listings l
        LEFT JOIN cammarket237.stores s ON s.id=l.store_id WHERE l.id=?", [$listingId]);
    if (!$listing || $listing['user_id'] != $user['id']) fail('Not your listing.');

    $oldPrice = floatval($listing['price']);
    $priceChanged = $price && $price != $oldPrice;

    db()->prepare("UPDATE cammarket237.listings SET
        title = COALESCE(NULLIF(?,''), title),
        description = COALESCE(NULLIF(?,''), description),
        price = COALESCE(?,price),
        category = COALESCE(NULLIF(?,''), category),
        price_type = COALESCE(NULLIF(?,''), price_type),
        condition = COALESCE(NULLIF(?,''), condition),
        updated_at = NOW()
        WHERE id=?")->execute([$title,$desc,$price,$category,$priceType,$condition,$listingId]);

    // Handle photo replacement - track toward monthly limit
    $isPhotoReplacement = !empty($_POST['photo_replacement']);
    if ($isPhotoReplacement && (!empty($_FILES['photo1']['name']) || !empty($_FILES['photo2']['name']))) {
        // Track this as a listing slot used (for future subscription system)
        try {
            db()->prepare("INSERT INTO cammarket237.listing_slots_used
                (seller_id, listing_id, slot_type, created_at)
                VALUES (?,?,'photo_replacement',NOW())
                ON CONFLICT DO NOTHING")->execute([$user['id'], $listingId]);
        } catch(Exception $e) {
            // Table may not exist yet - that's ok, we log it
            error_log('Slot tracking: ' . $e->getMessage());
        }
    }
    if (!empty($_FILES['photo1']['name'])) {
        $p1 = saveFile($_FILES['photo1'], 'photo');
        if ($p1['ok']) {
            db()->prepare("UPDATE cammarket237.listing_media SET media_url=?
                WHERE listing_id=? AND media_role='main_image'")->execute([$p1['url'], $listingId]);
        }
    }
    if (!empty($_FILES['photo2']['name'])) {
        $p2 = saveFile($_FILES['photo2'], 'photo');
        if ($p2['ok']) {
            db()->prepare("UPDATE cammarket237.listing_media SET media_url=?
                WHERE listing_id=? AND media_role='extra_image'")->execute([$p2['url'], $listingId]);
        }
    }

    // Notify buyers who saved or carted this item
    $buyers = q("SELECT DISTINCT buyer_id FROM (
        SELECT buyer_id FROM cammarket237.cart_items WHERE listing_id=?
    ) combined", [$listingId]);

    $msg = $priceChanged
        ? ($listing['title'] ?? 'An item') . ' price updated: ' . number_format($oldPrice) . ' → ' . number_format($price) . ' FCFA'
        : ($listing['title'] ?? 'An item') . ' in your cart has been updated by the seller.';

    $notifStmt = db()->prepare("INSERT INTO cammarket237.cart_notifications
        (buyer_id, listing_id, notification_type, message) VALUES (?,?,?,?)");
    foreach ($buyers as $b) {
        if ($b['buyer_id']) {
            $notifStmt->execute([$b['buyer_id'], $listingId,
                $priceChanged ? 'price_update' : 'listing_update', $msg]);
        }
    }

    ok(['message' => 'Listing updated! ' . count($buyers) . ' buyers notified.',
        'price_changed' => $priceChanged, 'buyers_notified' => count($buyers)]);
}

// ═══════════════════════════════════════════════════════════
// DELETE LISTING (marks as sold with badge)
// ═══════════════════════════════════════════════════════════

if ($action === 'delete_listing') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');
    $listingId = intval(p('listing_id'));

    $listing = q1("SELECT l.*, s.user_id FROM cammarket237.listings l
        LEFT JOIN cammarket237.stores s ON s.id=l.store_id WHERE l.id=?", [$listingId]);
    if (!$listing || $listing['user_id'] != $user['id']) fail('Not your listing.');

    // Mark as sold (stays on platform with SOLD badge)
    db()->prepare("UPDATE cammarket237.listings
        SET is_sold=true, sold_at=NOW(), status='sold' WHERE id=?")
        ->execute([$listingId]);

    // Notify buyers in cart
    $buyers = q("SELECT DISTINCT buyer_id FROM cammarket237.cart_items WHERE listing_id=?", [$listingId]);
    $notifStmt = db()->prepare("INSERT INTO cammarket237.cart_notifications
        (buyer_id, listing_id, notification_type, message) VALUES (?,?,?,?)");
    foreach ($buyers as $b) {
        $notifStmt->execute([$b['buyer_id'], $listingId, 'removed',
            ($listing['title'] ?? 'An item') . ' in your cart has been removed by the seller.']);
    }

    ok(['message' => 'Listing removed. ' . count($buyers) . ' buyers notified.']);
}



// ═══════════════════════════════════════════════════════════
// STOCK STATUS MANAGEMENT
// ═══════════════════════════════════════════════════════════

if ($action === 'update_stock_status') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');
    $listingId   = intval(p('listing_id'));
    $stockStatus = p('stock_status'); // in_stock | out_of_stock | coming_soon
    $quantity    = p('quantity') ? intval(p('quantity')) : null;
    if (!$listingId || !$stockStatus) fail('Missing fields.');

    $listing = q1("SELECT l.*, s.user_id FROM cammarket237.listings l
        LEFT JOIN cammarket237.stores s ON s.id=l.store_id WHERE l.id=?", [$listingId]);
    if (!$listing || $listing['user_id'] != $user['id']) fail('Not your listing.');

    $wasOutOfStock = ($listing['stock_status'] === 'out_of_stock' || $listing['stock_status'] === 'coming_soon');
    $nowInStock    = ($stockStatus === 'in_stock');

    db()->prepare("UPDATE cammarket237.listings SET
        stock_status=?, quantity_available=COALESCE(?,quantity_available)
        WHERE id=?")->execute([$stockStatus, $quantity, $listingId]);

    // If restocked - notify all buyers who clicked Notify Me
    $notified = 0;
    if ($wasOutOfStock && $nowInStock) {
        $waitlist = q("SELECT DISTINCT buyer_id FROM cammarket237.stock_notify
            WHERE listing_id=? AND notified=false", [$listingId]);
        $notifStmt = db()->prepare("INSERT INTO cammarket237.cart_notifications
            (buyer_id, listing_id, notification_type, message) VALUES (?,?,?,?)");
        foreach ($waitlist as $w) {
            $notifStmt->execute([$w['buyer_id'], $listingId, 'back_in_stock',
                ($listing['title']??'An item') . ' is back in stock! Tap to buy now.']);
        }
        db()->prepare("UPDATE cammarket237.stock_notify SET notified=true, notified_at=NOW()
            WHERE listing_id=?")->execute([$listingId]);
        $notified = count($waitlist);
    }

    ok(['message' => 'Stock status updated!', 'buyers_notified' => $notified,
        'stock_status' => $stockStatus]);
}

if ($action === 'notify_me') {
    $user = authUser();
    if (!$user || $user['role'] !== 'buyer') fail('Buyers only.');
    $listingId = intval(p('listing_id'));
    if (!$listingId) fail('Missing listing_id.');
    try {
        db()->prepare("INSERT INTO cammarket237.stock_notify
            (buyer_id, listing_id) VALUES (?,?)
            ON CONFLICT (buyer_id, listing_id) DO NOTHING")
            ->execute([$user['id'], $listingId]);
        $count = q1("SELECT COUNT(*) AS n FROM cammarket237.stock_notify
            WHERE listing_id=? AND notified=false", [$listingId]);
        ok(['message' => 'You will be notified when this item is available!',
            'waitlist_count' => intval($count['n'])]);
    } catch(Exception $e) { fail($e->getMessage()); }
}

if ($action === 'get_waitlist_count') {
    $listingId = intval(p('listing_id') ?: g('listing_id'));
    if (!$listingId) fail('Missing listing_id.');
    $cnt = q1("SELECT COUNT(*) AS n FROM cammarket237.stock_notify
        WHERE listing_id=? AND notified=false", [$listingId]);
    ok(['count' => intval($cnt['n'])]);
}



// ═══════════════════════════════════════════════════════════
// SOFT DELETE ACCOUNT (marks inactive, not permanently deleted)
// ═══════════════════════════════════════════════════════════
if ($action === 'delete_account') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $pass = p('password');
    if (!$pass) fail('Password required to delete account.');

    // Verify password
    $u = q1("SELECT * FROM cammarket237.users WHERE id=?", [$user['id']]);
    if (!$u || !password_verify($pass, $u['password_hash'])) fail('Incorrect password.');

    db()->beginTransaction();
    try {
        // Mark user inactive
        db()->prepare("UPDATE cammarket237.users SET
            is_active = false,
            deleted_at = NOW(),
            session_token = NULL,
            session_expires_at = NOW(),
            phone = CONCAT('DELETED_', id, '_', phone)
            WHERE id=?")->execute([$user['id']]);

        // Mark their listings inactive
        if ($u['role'] === 'seller') {
            db()->prepare("UPDATE cammarket237.listings SET status='inactive'
                WHERE store_id IN (SELECT id FROM cammarket237.stores WHERE user_id=?)")
                ->execute([$user['id']]);

            // Mark store inactive
            db()->prepare("UPDATE cammarket237.stores SET is_active=false WHERE user_id=?")
                ->execute([$user['id']]);
        }

        db()->commit();
        ok(['message' => 'Account deactivated. Your data is kept securely per our privacy policy.']);
    } catch(Exception $e) {
        db()->rollBack();
        fail($e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════
// REACTIVATE ACCOUNT
// ═══════════════════════════════════════════════════════════
if ($action === 'reactivate_account') {
    $phone = p('phone');
    $pass  = p('password');
    if (!$phone || !$pass) fail('Phone and password required.');

    $u = q1("SELECT * FROM cammarket237.users
        WHERE phone LIKE CONCAT('DELETED_%_', ?) AND is_active=false LIMIT 1", [$phone]);

    if (!$u) fail('No deactivated account found for this phone.');
    if (!password_verify($pass, $u['password_hash'])) fail('Incorrect password.');

    // Restore original phone
    $originalPhone = preg_replace('/^DELETED_\d+_/', '', $u['phone']);
    $tok = token();
    $exp = date('Y-m-d H:i:s', strtotime('+'.SESSION_HOURS.' hours'));

    db()->prepare("UPDATE cammarket237.users SET
        is_active = true,
        deleted_at = NULL,
        phone = ?,
        session_token = ?,
        session_expires_at = ?
        WHERE id=?")->execute([$originalPhone, $tok, $exp, $u['id']]);

    // Reactivate listings if seller
    if ($u['role'] === 'seller') {
        db()->prepare("UPDATE cammarket237.listings SET status='active'
            WHERE store_id IN (SELECT id FROM cammarket237.stores WHERE user_id=?)")
            ->execute([$u['id']]);
        db()->prepare("UPDATE cammarket237.stores SET is_active=true WHERE user_id=?")
            ->execute([$u['id']]);
    }

    $user = q1("SELECT id,full_name,phone,role,region,town,session_token,referral_code
        FROM cammarket237.users WHERE id=?", [$u['id']]);

    ok(['message' => 'Account reactivated! Welcome back.', 'user' => $user]);
}



// ═══════════════════════════════════════════════════════════
// GET SINGLE LISTING
// ═══════════════════════════════════════════════════════════
if ($action === 'get_listing') {
    $id = intval(g('id'));
    $listing = q1("SELECT l.*,
        (SELECT media_url FROM cammarket237.listing_media WHERE listing_id=l.id AND media_role='main_image' ORDER BY sort_order LIMIT 1) AS main_photo,
        (SELECT media_url FROM cammarket237.listing_media WHERE listing_id=l.id AND media_role='extra_image' ORDER BY sort_order LIMIT 1) AS photo2,
        (SELECT media_url FROM cammarket237.listing_media WHERE listing_id=l.id AND media_role='extra_image' ORDER BY sort_order LIMIT 1 OFFSET 1) AS photo3,
        (SELECT media_url FROM cammarket237.listing_media WHERE listing_id=l.id AND media_role IN ('video_360','video','360view') ORDER BY sort_order LIMIT 1) AS video360
        FROM cammarket237.listings l WHERE l.id=?", [$id]);
    if (!$listing) fail('Listing not found.');
    ok(['listing' => $listing]);
}

// ═══════════════════════════════════════════════════════════
// EDIT LISTING (title, price, description, stock + photos)
// ═══════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════
// DELETE LISTING
// ═══════════════════════════════════════════════════════════
if ($action === 'delete_listing') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');
    $listingId = intval(p('listing_id'));

    // Verify ownership
    $listing = q1("SELECT l.* FROM cammarket237.listings l
        JOIN cammarket237.stores s ON s.id=l.store_id
        WHERE l.id=? AND s.user_id=?", [$listingId, $user['id']]);
    if (!$listing) fail('Listing not found or not yours.');

    // Soft delete - mark as inactive
    db()->prepare("UPDATE cammarket237.listings SET status='inactive', updated_at=NOW() WHERE id=?")->execute([$listingId]);

    // Notify cart holders
    $cartBuyers = q("SELECT buyer_id FROM cammarket237.cart_items WHERE listing_id=?", [$listingId]);
    foreach ($cartBuyers as $b) {
        try {
            db()->prepare("INSERT INTO cammarket237.cart_notifications 
                (buyer_id, listing_id, notification_type, message)
                VALUES (?,?,?,?)")->execute([
                $b['buyer_id'], $listingId, 'item_deleted',
                'An item in your cart has been removed by the seller.'
            ]);
        } catch(Exception $e) {}
    }

    ok(['message' => 'Listing deleted.']);
}


// ── CHECK SESSION ──────────────────────────────────────────
if($action === 'check_session'){
    $tok = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? g('token') ?? '';
    if(!$tok) fail('No token.');
    try {
        $s = db()->prepare("SELECT u.id,u.full_name,u.phone,u.role,u.region,u.town,
            u.area_quarter,u.session_token,u.referral_code,
            COALESCE(u.wallet_balance,0) AS wallet_balance,
            s.id as store_id,s.store_name,s.whatsapp,s.latitude,s.longitude,s.rating
            FROM cammarket237.users u
            LEFT JOIN cammarket237.stores s ON s.user_id=u.id
            WHERE u.session_token=? AND u.session_expires_at>NOW() LIMIT 1");
        $s->execute([$tok]); $row=$s->fetch();
        if(!$row) fail('Session expired.');
        $user=['id'=>$row['id'],'full_name'=>$row['full_name'],'phone'=>$row['phone'],
            'role'=>$row['role'],'region'=>$row['region']??'',
            'town'=>$row['town']??$row['area_quarter']??'',
            'session_token'=>$tok,'referral_code'=>$row['referral_code']??'',
            'wallet_balance'=>intval($row['wallet_balance']??0)];
        $store=null;
        if($row['store_id']){
            $store=['id'=>$row['store_id'],'store_name'=>$row['store_name'],
                'whatsapp'=>$row['whatsapp'],'latitude'=>$row['latitude'],
                'longitude'=>$row['longitude'],'rating'=>$row['rating']];
        }
        ok(['user'=>$user,'store'=>$store]);
    } catch(Exception $e){ fail($e->getMessage()); }
}

if($action === 'get_reviews'){
    $store_id = intval(g('store_id'));
    if(!$store_id) fail('Store ID required.');
    try {
        $s = db()->prepare("SELECT r.rating, r.comment, r.created_at,
            u.full_name AS reviewer_name
            FROM cammarket237.reviews r
            JOIN cammarket237.users u ON r.reviewer_id=u.id
            WHERE r.store_id=?
            ORDER BY r.created_at DESC LIMIT 20");
        $s->execute([$store_id]);
        $reviews = $s->fetchAll();
        // Get average rating
        $avg = db()->prepare("SELECT ROUND(AVG(rating),1) as avg, COUNT(*) as total FROM cammarket237.reviews WHERE store_id=?");
        $avg->execute([$store_id]);
        $stats = $avg->fetch();
        ok(['reviews'=>$reviews, 'avg'=>$stats['avg'], 'total'=>$stats['total']]);
    } catch(Exception $e){ fail($e->getMessage()); }
}

// ── SUBMIT REVIEW ──────────────────────────────────
if($action === 'submit_review'){
    $tok = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? p('session_token');
    if(!$tok) fail('Please login to submit a review.');
    try {
        $us = db()->prepare("SELECT * FROM cammarket237.users WHERE session_token=? AND session_expires_at>NOW() LIMIT 1");
        $us->execute([$tok]); $user = $us->fetch();
        if(!$user) fail('Session expired.');
        if($user['role'] !== 'buyer') fail('Only buyers can submit reviews.');
        $store_id = intval(p('store_id'));
        $rating = intval(p('rating'));
        $comment = trim(p('comment'));
        if(!$store_id||!$rating) fail('Store and rating required.');
        if($rating < 1 || $rating > 5) fail('Rating must be 1-5.');
        // Check not already reviewed
        $chk = db()->prepare("SELECT id FROM cammarket237.reviews WHERE reviewer_id=? AND store_id=? LIMIT 1");
        $chk->execute([$user['id'], $store_id]);
        if($chk->fetch()){
            // Update existing
            db()->prepare("UPDATE cammarket237.reviews SET rating=?, comment=?, created_at=NOW() WHERE reviewer_id=? AND store_id=?")->execute([$rating,$comment,$user['id'],$store_id]);
        } else {
            db()->prepare("INSERT INTO cammarket237.reviews(reviewer_id,store_id,rating,comment,created_at) VALUES(?,?,?,?,NOW())")->execute([$user['id'],$store_id,$rating,$comment]);
        }
        // Update store average rating
        $avg = db()->prepare("SELECT ROUND(AVG(rating),2) FROM cammarket237.reviews WHERE store_id=?");
        $avg->execute([$store_id]);
        $newAvg = $avg->fetchColumn();
        db()->prepare("UPDATE cammarket237.stores SET rating=? WHERE id=?")->execute([$newAvg,$store_id]);
        ok(['message'=>'Review submitted!']);
    } catch(Exception $e){ fail($e->getMessage()); }
}

// ── FOLLOW / UNFOLLOW ──────────────────────────────
if($action === 'follow' || $action === 'unfollow'){
    $tok = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? p('session_token');
    if(!$tok) fail('Please login.');
    try {
        $us = db()->prepare("SELECT id FROM cammarket237.users WHERE session_token=? AND session_expires_at>NOW() LIMIT 1");
        $us->execute([$tok]); $user = $us->fetch();
        if(!$user) fail('Session expired.');
        $store_id = intval(p('store_id'));
        if(!$store_id) fail('Store ID required.');
        // Get store owner user_id
        $st = db()->prepare("SELECT user_id FROM cammarket237.stores WHERE id=? LIMIT 1");
        $st->execute([$store_id]); $store = $st->fetch();
        if(!$store) fail('Store not found.');
        if($action === 'follow'){
            // Insert ignore duplicate
            try {
                db()->prepare("INSERT INTO cammarket237.followers(follower_id,following_id,created_at) VALUES(?,?,NOW())")->execute([$user['id'],$store['user_id']]);
            } catch(Exception $e2){}
            ok(['message'=>'Following!']);
        } else {
            db()->prepare("DELETE FROM cammarket237.followers WHERE follower_id=? AND following_id=?")->execute([$user['id'],$store['user_id']]);
            ok(['message'=>'Unfollowed.']);
        }
    } catch(Exception $e){ fail($e->getMessage()); }
}

// ── GET FOLLOWERS COUNT ────────────────────────────
if($action === 'get_followers'){
    $store_id = intval(g('store_id'));
    try {
        $st = db()->prepare("SELECT user_id FROM cammarket237.stores WHERE id=? LIMIT 1");
        $st->execute([$store_id]); $store = $st->fetch();
        if(!$store){ ok(['count'=>0]); }
        $cnt = db()->prepare("SELECT COUNT(*) FROM cammarket237.followers WHERE following_id=?");
        $cnt->execute([$store['user_id']]);
        ok(['count'=>intval($cnt->fetchColumn())]);
    } catch(Exception $e){ ok(['count'=>0]); }
}

// ── GET SERVICES ──────────────────────────────────────────
if($action === 'get_services'){
    $type   = g('type'); $q = g('q'); $range = g('range')?:'national';
    $town   = g('town'); $region = g('region');
    $lat    = g('lat');  $lng = g('lng');

    $where  = ["sl.status='active'"]; $params = [];
    if($type) { $where[] = "sl.service_type=:type"; $params[':type'] = $type; }
    if($q)    { $where[] = "(sl.title ILIKE :q OR sl.description ILIKE :q)"; $params[':q'] = '%'.$q.'%'; }
    if($range==='town'&&$town)   { $where[] = "sl.town=:town";     $params[':town']   = $town; }
    if($range==='region'&&$region){ $where[] = "s.region=:region"; $params[':region'] = $region; }
    $params[':bt'] = $town; $params[':br'] = $region;
    $w = implode(' AND ', $where);

    $distCol = ''; $orderBy = "ORDER BY sl.created_at DESC";
    if($lat && $lng) {
        $distCol = ",(6371*acos(GREATEST(-1,LEAST(1,cos(radians($lat))*cos(radians(sl.latitude))*cos(radians(sl.longitude)-radians($lng))+sin(radians($lat))*sin(radians(sl.latitude)))))) AS distance";
        $orderBy = "ORDER BY distance ASC";
    } else {
        $orderBy = "ORDER BY CASE WHEN sl.town=:bt THEN 0 WHEN s.region=:br THEN 1 ELSE 2 END, sl.created_at DESC";
    }

    try {
        $sql = "SELECT sl.id,sl.service_type,sl.title,sl.description,sl.price,sl.price_unit,
                sl.availability,sl.amenities,sl.town,sl.latitude,sl.longitude,sl.created_at,
                s.store_name,s.whatsapp,s.region,s.rating,s.id AS store_id,
                (SELECT media_url FROM cammarket237.service_media WHERE service_id=sl.id AND media_role='main' LIMIT 1) AS main_photo,
                (SELECT media_url FROM cammarket237.service_media WHERE service_id=sl.id AND media_role='extra' LIMIT 1) AS photo2
                $distCol
                FROM cammarket237.service_listings sl
                LEFT JOIN cammarket237.stores s ON sl.store_id=s.id
                WHERE $w $orderBy LIMIT 40";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        ok(['services'=>$stmt->fetchAll()]);
    } catch(Exception $e){ fail($e->getMessage()); }
}

// ── POST SERVICE ───────────────────────────────────────────
if($action === 'post_service'){
    $tok = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? p('session_token');
    if(!$tok) fail('Please login first.');
    try {
        $us = db()->prepare("SELECT * FROM cammarket237.users WHERE session_token=? AND session_expires_at>NOW() LIMIT 1");
        $us->execute([$tok]); $user = $us->fetch();
        if(!$user) fail('Session expired. Please login again.');
        if($user['role'] !== 'seller') fail('Only sellers can post services.');
        foreach(['service_type','title','description'] as $f)
            if(empty($_POST[$f])) fail("Missing: $f");
        if(empty($_FILES['photo1']['name'])) fail('Please upload at least 1 photo.');

        // Create service_listings table if not exists
        db()->exec("CREATE TABLE IF NOT EXISTS cammarket237.service_listings (
            id SERIAL PRIMARY KEY, user_id INTEGER, store_id INTEGER,
            service_type VARCHAR(50), title VARCHAR(200), description TEXT,
            price NUMERIC(12,2), price_unit VARCHAR(20), availability VARCHAR(200),
            amenities TEXT, town VARCHAR(100), region VARCHAR(100),
            latitude DECIMAL(10,8), longitude DECIMAL(11,8),
            status VARCHAR(20) DEFAULT 'active', created_at TIMESTAMP DEFAULT NOW()
        )");
        db()->exec("CREATE TABLE IF NOT EXISTS cammarket237.service_media (
            id SERIAL PRIMARY KEY, service_id INTEGER, media_url TEXT,
            media_role VARCHAR(20), created_at TIMESTAMP DEFAULT NOW()
        )");

        // Save photos
        function svcSaveFile($file){
            if(!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR,0755,true);
            $imgs=['image/jpeg','image/png','image/webp'];
            if(!in_array($file['type'],$imgs)) return ['ok'=>false,'error'=>'JPG/PNG/WEBP only'];
            $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
            $name=uniqid('svc_',true).'.'.$ext;
            if(!move_uploaded_file($file['tmp_name'],UPLOAD_DIR.$name)) return ['ok'=>false,'error'=>'Upload failed'];
            return ['ok'=>true,'url'=>UPLOAD_URL.$name];
        }

        $p1=svcSaveFile($_FILES['photo1']); if(!$p1['ok']) fail($p1['error']);
        $p2=['ok'=>true,'url'=>null]; if(!empty($_FILES['photo2']['name'])) $p2=svcSaveFile($_FILES['photo2']);
        $p3=['ok'=>true,'url'=>null]; if(!empty($_FILES['photo3']['name'])) $p3=svcSaveFile($_FILES['photo3']);

        $stmt = db()->prepare("INSERT INTO cammarket237.service_listings
            (user_id,store_id,service_type,title,description,price,price_unit,availability,amenities,town,region,latitude,longitude,status,created_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'active',NOW()) RETURNING id");
        $stmt->execute([$user['id'],intval(p('store_id')),p('service_type'),p('title'),p('description'),
            p('price')?:null,p('price_unit')?:'negotiable',p('availability'),p('amenities'),
            p('town'),p('region'),p('latitude')?:null,p('longitude')?:null]);
        $sid = $stmt->fetch()['id'];

        $ms = db()->prepare("INSERT INTO cammarket237.service_media(service_id,media_url,media_role,created_at) VALUES(?,?,?,NOW())");
        $ms->execute([$sid,$p1['url'],'main']);
        if($p2['url']) $ms->execute([$sid,$p2['url'],'extra']);
        if($p3['url']) $ms->execute([$sid,$p3['url'],'extra']);

        ok(['service_id'=>$sid,'message'=>'Service posted successfully!']);
    } catch(Exception $e){ fail('Error: '.$e->getMessage()); }
}


// ── BUYER LOGIN ────────────────────────────────────────
if($action === 'buyer_login'){
    $phone = trim(p('phone')); $pass = p('password');
    $ip=getClientIP(); $rl=checkRateLimit($ip.'_'.$phone,'buyer_login',5,300); if(!$rl['allowed']) fail('Too many login attempts. Wait '.$rl['wait_minutes'].' min(s).');
    if(!$phone || !$pass) fail('Phone and password required.');
    try {
        $user = findUserByPhone($phone, 'buyer');
        if(!$user) fail('No buyer account found. Check your phone number or register.');
        if(!password_verify($pass, $user['password_hash'])) fail('Incorrect password.');
        $tok = bin2hex(random_bytes(32));
        $exp = date('Y-m-d H:i:s', strtotime('+'.SESSION_HOURS.' hours'));
        db()->prepare("UPDATE cammarket237.users SET session_token=?,session_expires_at=?,last_login_at=NOW() WHERE id=?")->execute([$tok,$exp,$user['id']]);
        ok(['user'=>['id'=>$user['id'],'full_name'=>$user['full_name'],'phone'=>$user['phone'],
            'role'=>$user['role'],'region'=>$user['region']??'','town'=>$user['town']??$user['area_quarter']??'',
            'session_token'=>$tok,'referral_code'=>$user['referral_code']??'',
            'wallet_balance'=>intval($user['wallet_balance']??0)],
            'has_pin'=>!empty($user['recovery_pin_hash'])]);
    } catch(Exception $e){ fail($e->getMessage()); }
}


// ═══════════════════════════════════════════════════════════
// LIVE STREAMING SYSTEM - 100ms.live
// Credentials stored securely server-side
// ═══════════════════════════════════════════════════════════
define('HMS_APP_ID',     '1607098');
define('HMS_APP_SECRET', '6dkpbh75qs3g');
define('HMS_RATE_PER_MIN', 10); // 10 FCFA per minute

function hmsGenerateToken($roomId, $userId, $role = 'host') {
    $header = base64_encode(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $now = time();
    $payload = base64_encode(json_encode([
        'access_key' => HMS_APP_ID,
        'room_id'    => $roomId,
        'user_id'    => (string)$userId,
        'role'       => $role,
        'type'       => 4,
        'version'    => 2,
        'iat'        => $now,
        'exp'        => $now + 86400,
        'jti'        => uniqid()
    ]));
    $sig = base64_encode(hash_hmac('sha256', "$header.$payload", HMS_APP_SECRET, true));
    return "$header.$payload.$sig";
}

function hmsCreateRoom($title) {
    $ch = curl_init('https://api.100ms.live/v2/rooms');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . hmsGenerateToken('', 0, 'host'),
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'name'        => 'cm237-' . time(),
            'description' => $title,
            'template_id' => 'default'
        ])
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// ── PLATFORM SETTINGS ─────────────────────────────────────
if ($action === 'get_platform_settings') {
    $settings = q("SELECT key, value FROM cammarket237.platform_settings");
    $result = [];
    foreach ($settings as $s) $result[$s['key']] = $s['value'];
    ok(['settings' => $result]);
}

// ── GET SELLER STREAM BALANCE ──────────────────────────────
if ($action === 'get_stream_balance') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $bal = q1("SELECT * FROM cammarket237.stream_balance WHERE seller_id=?", [$user['id']]);
    ok(['balance' => $bal ?: ['minutes_available' => 0, 'minutes_used_total' => 0]]);
}

// ── START LIVE STREAM ──────────────────────────────────────
if ($action === 'start_stream') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');

    // Check streaming enabled
    $setting = q1("SELECT value FROM cammarket237.platform_settings WHERE key='live_streaming_enabled'");
    if (!$setting || $setting['value'] !== 'true') fail('Live streaming is currently disabled.');

    // Check balance
    $bal = q1("SELECT * FROM cammarket237.stream_balance WHERE seller_id=?", [$user['id']]);
    if (!$bal || $bal['minutes_available'] < 1) fail('Insufficient balance. Please buy streaming minutes.');

    $title    = p('title') ?: 'Live from ' . ($user['full_name'] ?? 'Seller');
    $storeId  = intval(p('store_id') ?? 0);

    // Create 100ms room
    $room = hmsCreateRoom($title);
    $roomId = $room['id'] ?? ('room-' . uniqid());

    // Generate host token
    $token = hmsGenerateToken($roomId, $user['id'], 'host');

    db()->beginTransaction();
    try {
        $stmt = db()->prepare("INSERT INTO cammarket237.live_streams
            (seller_id, store_id, title, status, minutes_balance, webrtc_room_id, started_at)
            VALUES (?,?,?,'live',?,?,NOW()) RETURNING id");
        $stmt->execute([$user['id'], $storeId, $title, $bal['minutes_available'], $roomId]);
        $stream = $stmt->fetch();
        $streamId = $stream['id'];

        // Notify ALL users
        $users = q("SELECT id FROM cammarket237.users WHERE role IN ('buyer','seller') AND id != ?", [$user['id']]);
        $storeName = q1("SELECT store_name FROM cammarket237.stores WHERE user_id=?", [$user['id']]);
        $notifStmt = db()->prepare("INSERT INTO cammarket237.cart_notifications
            (buyer_id, listing_id, notification_type, message) VALUES (?,?,?,?)");
        foreach ($users as $u) {
            try {
                $notifStmt->execute([$u['id'], 0, 'live_stream',
                    '🔴 LIVE NOW: ' . ($storeName['store_name'] ?? $user['full_name']) . ' - "' . $title . '" - Tap to watch!']);
            } catch(Exception $e) {}
        }
        db()->commit();

        ok([
            'stream_id' => $streamId,
            'room_id'   => $roomId,
            'token'     => $token,
            'title'     => $title,
            'minutes'   => $bal['minutes_available'],
            'notified'  => count($users),
        ]);
    } catch(Exception $e) {
        db()->rollBack();
        fail($e->getMessage());
    }
}

// ── JOIN STREAM AS VIEWER ──────────────────────────────────
if ($action === 'join_stream') {
    $streamId = intval(g('stream_id'));
    $stream = q1("SELECT * FROM cammarket237.live_streams WHERE id=? AND status='live'", [$streamId]);
    if (!$stream) fail('Stream not found or ended.');

    $userId = 0;
    $userName = 'Guest';
    try {
        $tok = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
        if ($tok) {
            $u = q1("SELECT id, full_name FROM cammarket237.users WHERE session_token=? LIMIT 1", [$tok]);
            if ($u) { $userId = $u['id']; $userName = $u['full_name']; }
        }
    } catch(Exception $e) {}

    $token = hmsGenerateToken($stream['webrtc_room_id'], $userId ?: uniqid(), 'viewer');

    // Update viewer count
    db()->prepare("UPDATE cammarket237.live_streams SET viewer_count=viewer_count+1,
        peak_viewers=GREATEST(peak_viewers,viewer_count+1) WHERE id=?")->execute([$streamId]);

    ok([
        'token'   => $token,
        'room_id' => $stream['webrtc_room_id'],
        'title'   => $stream['title'],
        'viewers' => $stream['viewer_count'] + 1,
    ]);
}

// ── TICK (called every minute to deduct balance) ───────────
if ($action === 'stream_tick') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $streamId = intval(p('stream_id'));

    $stream = q1("SELECT * FROM cammarket237.live_streams WHERE id=? AND seller_id=?", [$streamId, $user['id']]);
    if (!$stream || $stream['status'] !== 'live') fail('Stream not active.');

    $bal = q1("SELECT * FROM cammarket237.stream_balance WHERE seller_id=?", [$user['id']]);
    $mins = floatval($bal['minutes_available'] ?? 0);
    $newMins = max(0, $mins - 1);

    // Deduct 1 minute
    db()->prepare("UPDATE cammarket237.stream_balance SET minutes_available=?, updated_at=NOW() WHERE seller_id=?")
        ->execute([$newMins, $user['id']]);
    db()->prepare("UPDATE cammarket237.live_streams SET minutes_used=minutes_used+1, minutes_balance=? WHERE id=?")
        ->execute([$newMins, $streamId]);

    $warning = $newMins <= 3 && $newMins > 0;
    $ended   = $newMins <= 0;

    if ($ended) {
        db()->prepare("UPDATE cammarket237.live_streams SET status='ended', ended_at=NOW() WHERE id=?")
            ->execute([$streamId]);
    }

    ok(['minutes_left' => $newMins, 'warning' => $warning, 'ended' => $ended]);
}

// ── END STREAM ─────────────────────────────────────────────
if ($action === 'end_stream') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $streamId = intval(p('stream_id'));
    db()->prepare("UPDATE cammarket237.live_streams SET status='ended', ended_at=NOW() WHERE id=? AND seller_id=?")
        ->execute([$streamId, $user['id']]);
    ok(['message' => 'Stream ended.']);
}

// ── POST COMMENT ───────────────────────────────────────────
if ($action === 'post_comment') {
    $streamId = intval(p('stream_id'));
    $message  = trim(p('message') ?? '');
    if (!$message) fail('Empty message.');
    $userId = 0; $userName = 'Guest'; $userRole = 'guest';
    try {
        $tok = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
        if ($tok) {
            $u = q1("SELECT id, full_name, role FROM cammarket237.users WHERE session_token=? LIMIT 1", [$tok]);
            if ($u) { $userId = $u['id']; $userName = $u['full_name']; $userRole = $u['role']; }
        }
    } catch(Exception $e) {}
    db()->prepare("INSERT INTO cammarket237.live_comments (stream_id,user_id,user_name,user_role,message) VALUES (?,?,?,?,?)")
        ->execute([$streamId, $userId ?: null, $userName, $userRole, $message]);
    ok(['message' => 'Comment posted.']);
}

// ── GET COMMENTS ───────────────────────────────────────────
if ($action === 'get_comments') {
    $streamId = intval(g('stream_id'));
    $since    = g('since') ?: '1970-01-01';
    $comments = q("SELECT id, user_name, user_role, message, created_at
        FROM cammarket237.live_comments WHERE stream_id=? AND created_at > ?
        ORDER BY created_at ASC LIMIT 50", [$streamId, $since]);
    ok(['comments' => $comments]);
}

// ── GET ACTIVE STREAMS (for buyers) ───────────────────────
if ($action === 'get_live_streams') {
    $streams = q("SELECT ls.*, s.store_name, u.full_name
        FROM cammarket237.live_streams ls
        LEFT JOIN cammarket237.stores s ON s.user_id=ls.seller_id
        LEFT JOIN cammarket237.users u ON u.id=ls.seller_id
        WHERE ls.status='live'
        ORDER BY ls.viewer_count DESC");
    ok(['streams' => $streams]);
}

// ── ADMIN: ADD MINUTES TO SELLER ──────────────────────────
if (!defined('ADMIN_PASS')) define('ADMIN_PASS', getenv('ADMIN_PASS') ?: 'CamAdmin2024!');
if ($action === 'admin_add_minutes') {
    // Simple admin check via secret
    if (p('admin_pass') !== ADMIN_PASS) fail('Unauthorized.');
    $sellerId = intval(p('seller_id'));
    $minutes  = floatval(p('minutes'));
    $amountFcfa = intval(p('amount_fcfa') ?? 0);
    $note = p('note') ?: 'Admin top-up';

    if (!$sellerId || $minutes <= 0) fail('Invalid seller or minutes.');

    // Check if first purchase → give 20 free bonus mins
    $bal = q1("SELECT * FROM cammarket237.stream_balance WHERE seller_id=?", [$sellerId]);
    $isFirst = !$bal || !$bal['first_purchase_bonus_given'];
    $bonusMins = $isFirst ? 20 : 0;
    $totalMins = $minutes + $bonusMins;

    if ($bal) {
        db()->prepare("UPDATE cammarket237.stream_balance
            SET minutes_available=minutes_available+?, first_purchase_bonus_given=true, updated_at=NOW()
            WHERE seller_id=?")->execute([$totalMins, $sellerId]);
    } else {
        db()->prepare("INSERT INTO cammarket237.stream_balance
            (seller_id, minutes_available, first_purchase_bonus_given)
            VALUES (?,?,true)")->execute([$sellerId, $totalMins]);
    }

    // Log transaction
    db()->prepare("INSERT INTO cammarket237.stream_transactions
        (seller_id, transaction_type, minutes_added, amount_fcfa, note)
        VALUES (?,?,?,?,?)")->execute([$sellerId, 'purchase', $minutes, $amountFcfa, $note]);

    if ($bonusMins > 0) {
        db()->prepare("INSERT INTO cammarket237.stream_transactions
            (seller_id, transaction_type, minutes_added, amount_fcfa, note)
            VALUES (?,?,?,0,'First purchase bonus')")->execute([$sellerId, 'bonus', $bonusMins]);
    }

    ok([
        'message'    => 'Minutes added! ' . ($bonusMins > 0 ? "🎁 +{$bonusMins} FREE bonus minutes added!" : ''),
        'total_mins' => $totalMins,
        'bonus_mins' => $bonusMins,
    ]);
}

// ── ADMIN: TOGGLE LIVE STREAMING ──────────────────────────
if ($action === 'admin_toggle_streaming') {
    if (p('admin_pass') !== ADMIN_PASS) fail('Unauthorized.');
    $enabled = p('enabled') === 'true' ? 'true' : 'false';
    db()->prepare("UPDATE cammarket237.platform_settings SET value=?, updated_at=NOW() WHERE key='live_streaming_enabled'")
        ->execute([$enabled]);
    ok(['enabled' => $enabled, 'message' => 'Live streaming ' . ($enabled === 'true' ? 'ENABLED' : 'DISABLED')]);
}


// ═══════════════════════════════════════════════════════════
// SAFETY & MEETING SYSTEM
// ═══════════════════════════════════════════════════════════

// ── LOG SAFETY ACCEPTANCE ─────────────────────────────────
if ($action === 'accept_safety') {
    $user = authUser();
    $listingId = intval(p('listing_id'));
    $sellerId  = intval(p('seller_id'));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    try {
        db()->prepare("INSERT INTO cammarket237.safety_acceptances (user_id,listing_id,seller_id,ip_address) VALUES (?,?,?,?)")
            ->execute([$user ? $user['id'] : 0, $listingId, $sellerId, $ip]);
    } catch(Exception $e) {}
    ok(['message' => 'Safety accepted.']);
}

// ── SCHEDULE MEETING ──────────────────────────────────────
if ($action === 'schedule_meeting') {
    $user = authUser();
    if (!$user || $user['role'] !== 'buyer') fail('Buyers only.');
    $listingId = intval(p('listing_id'));
    $sellerId  = intval(p('seller_id'));
    $storeId   = intval(p('store_id') ?? 0);
    $title     = p('item_title') ?? '';
    $price     = floatval(p('item_price') ?? 0);
    $scheduledAt = p('scheduled_at');
    $location  = p('location_description') ?? '';
    if (!$sellerId || !$scheduledAt) fail('Missing meeting details.');
    $stmt = db()->prepare("INSERT INTO cammarket237.meetings
        (listing_id,buyer_id,seller_id,store_id,item_title,item_price,scheduled_at,location_description,status)
        VALUES (?,?,?,?,?,?,?,?,'pending') RETURNING id");
    $stmt->execute([$listingId,$user['id'],$sellerId,$storeId,$title,$price,$scheduledAt,$location]);
    $meeting = $stmt->fetch();
    // Notify seller
    try {
        db()->prepare("INSERT INTO cammarket237.cart_notifications (buyer_id,listing_id,notification_type,message) VALUES (?,?,?,?)")
            ->execute([$sellerId, $listingId, 'meeting_request',
                '📅 Meeting request from a buyer for "'.$title.'" on '.date('D d M Y, g:ia', strtotime($scheduledAt)).' at '.$location]);
    } catch(Exception $e) {}
    ok(['meeting_id' => $meeting['id'], 'message' => 'Meeting scheduled! Waiting for seller confirmation.']);
}

// ── CONFIRM MEETING (seller) ───────────────────────────────
if ($action === 'confirm_meeting') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $meetingId = intval(p('meeting_id'));
    $meeting = q1("SELECT * FROM cammarket237.meetings WHERE id=?", [$meetingId]);
    if (!$meeting) fail('Meeting not found.');
    if ($user['role'] === 'seller') {
        db()->prepare("UPDATE cammarket237.meetings SET seller_confirmed=true, status='confirmed' WHERE id=?")->execute([$meetingId]);
        // Notify buyer
        try {
            db()->prepare("INSERT INTO cammarket237.cart_notifications (buyer_id,listing_id,notification_type,message) VALUES (?,?,?,?)")
                ->execute([$meeting['buyer_id'], $meeting['listing_id'], 'meeting_confirmed',
                    '✅ Seller confirmed your meeting for "'.$meeting['item_title'].'"! See you at '.$meeting['location_description']]);
        } catch(Exception $e) {}
    } elseif ($user['role'] === 'buyer') {
        db()->prepare("UPDATE cammarket237.meetings SET buyer_confirmed=true WHERE id=?")->execute([$meetingId]);
    }
    ok(['message' => 'Meeting confirmed!']);
}

// ── WE ARE TOGETHER (GPS capture) ─────────────────────────
if ($action === 'we_are_together') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $meetingId = intval(p('meeting_id'));
    $lat = floatval(p('lat'));
    $lng = floatval(p('lng'));
    $meeting = q1("SELECT * FROM cammarket237.meetings WHERE id=?", [$meetingId]);
    if (!$meeting) fail('Meeting not found.');
    $now = date('Y-m-d H:i:s');
    if ($user['id'] == $meeting['buyer_id']) {
        db()->prepare("UPDATE cammarket237.meetings SET buyer_lat=?,buyer_lng=?,buyer_checkin_at=? WHERE id=?")
            ->execute([$lat,$lng,$now,$meetingId]);
    } elseif ($user['role'] === 'seller') {
        db()->prepare("UPDATE cammarket237.meetings SET seller_lat=?,seller_lng=?,seller_checkin_at=? WHERE id=?")
            ->execute([$lat,$lng,$now,$meetingId]);
    }
    // Check if BOTH confirmed
    $updated = q1("SELECT * FROM cammarket237.meetings WHERE id=?", [$meetingId]);
    $bothIn = $updated['buyer_checkin_at'] && $updated['seller_checkin_at'];
    if ($bothIn && !$updated['together_confirmed_at']) {
        $avgLat = ($updated['buyer_lat'] + $updated['seller_lat']) / 2;
        $avgLng = ($updated['buyer_lng'] + $updated['seller_lng']) / 2;
        db()->prepare("UPDATE cammarket237.meetings SET together_confirmed_at=?,meeting_lat=?,meeting_lng=?,status='in_progress' WHERE id=?")
            ->execute([$now,$avgLat,$avgLng,$meetingId]);
        // Notify admin via cart_notifications
        try {
            db()->prepare("INSERT INTO cammarket237.cart_notifications (buyer_id,listing_id,notification_type,message) VALUES (?,?,?,?)")
                ->execute([1, 0, 'admin_meeting_alert',
                    '📍 MEETING IN PROGRESS: Buyer #'.$meeting['buyer_id'].' + Seller #'.$meeting['seller_id'].' for "'.$meeting['item_title'].'" at '.$avgLat.','.$avgLng]);
        } catch(Exception $e) {}
    }
    ok(['message' => 'Location confirmed!', 'both_together' => $bothIn, 'meeting' => $updated]);
}

// ── GET MY MEETINGS ────────────────────────────────────────
if ($action === 'get_my_meetings') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $col = $user['role'] === 'buyer' ? 'buyer_id' : 'seller_id';
    $meetings = q("SELECT m.*, 
        ub.full_name AS buyer_name, ub.phone AS buyer_phone,
        us.full_name AS seller_name, s.store_name,
        (SELECT AVG(rating) FROM cammarket237.buyer_reviews WHERE buyer_id=m.buyer_id) AS buyer_rating,
        (SELECT COUNT(*) FROM cammarket237.buyer_reviews WHERE buyer_id=m.buyer_id) AS buyer_review_count
        FROM cammarket237.meetings m
        LEFT JOIN cammarket237.users ub ON ub.id=m.buyer_id
        LEFT JOIN cammarket237.users us ON us.id=m.seller_id
        LEFT JOIN cammarket237.stores s ON s.id=m.store_id
        WHERE m.$col=? ORDER BY m.scheduled_at DESC LIMIT 20", [$user['id']]);
    ok(['meetings' => $meetings]);
}

// ── SUBMIT BUYER REVIEW (by seller) ──────────────────────
if ($action === 'review_buyer') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');
    $meetingId    = intval(p('meeting_id'));
    $buyerId      = intval(p('buyer_id'));
    $showedUp     = p('showed_up') === 'true';
    $onTime       = p('on_time') === 'true';
    $priceRespect = p('price_respected') === 'true';
    $respectful   = intval(p('respectful') ?? 3);
    $dealDone     = p('deal_completed') === 'true';
    $feltSafe     = intval(p('felt_safe') ?? 3);
    $rating       = intval(p('rating') ?? 3);
    $comment      = p('comment') ?? '';
    db()->prepare("INSERT INTO cammarket237.buyer_reviews
        (meeting_id,seller_id,buyer_id,showed_up,on_time,price_respected,respectful,deal_completed,felt_safe,rating,comment)
        VALUES (?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT (meeting_id,seller_id) DO UPDATE SET
        showed_up=EXCLUDED.showed_up,rating=EXCLUDED.rating,comment=EXCLUDED.comment")
        ->execute([$meetingId,$user['id'],$buyerId,$showedUp,$onTime,$priceRespect,$respectful,$dealDone,$feltSafe,$rating,$comment]);
    // Update buyer's aggregate rating
    $avg = q1("SELECT AVG(rating) AS avg, COUNT(*) AS cnt, SUM(CASE WHEN showed_up=false THEN 1 ELSE 0 END) AS noshows,
        SUM(CASE WHEN deal_completed=true THEN 1 ELSE 0 END) AS deals
        FROM cammarket237.buyer_reviews WHERE buyer_id=?", [$buyerId]);
    $badge = 'new';
    if ($avg['cnt'] >= 25 && $avg['avg'] >= 4.8) $badge = 'top_buyer';
    elseif ($avg['cnt'] >= 10 && $avg['avg'] >= 4.5) $badge = 'trusted';
    elseif ($avg['cnt'] >= 3) $badge = 'regular';
    db()->prepare("UPDATE cammarket237.users SET buyer_rating=?,buyer_review_count=?,
        no_show_count=?,deals_completed=?,buyer_badge=? WHERE id=?")
        ->execute([round($avg['avg'],2),$avg['cnt'],$avg['noshows'],$avg['deals'],$badge,$buyerId]);
    // Update meeting status
    db()->prepare("UPDATE cammarket237.meetings SET deal_completed=?,status='completed' WHERE id=?")
        ->execute([$dealDone,$meetingId]);
    ok(['message' => 'Buyer review submitted!']);
}

// ── SUBMIT SELLER REVIEW (by buyer) after meeting ─────────
if ($action === 'review_seller_post_meeting') {
    $user = authUser();
    if (!$user || $user['role'] !== 'buyer') fail('Buyers only.');
    $meetingId   = intval(p('meeting_id'));
    $storeId     = intval(p('store_id'));
    $rating      = intval(p('rating') ?? 3);
    $feltSafe    = intval(p('felt_safe') ?? 3);
    $itemAsDesc  = p('item_as_described') === 'true';
    $dealDone    = p('deal_completed') === 'true';
    $comment     = p('comment') ?? '';
    db()->prepare("INSERT INTO cammarket237.reviews
        (store_id,buyer_id,rating,comment,meeting_id,felt_safe,item_as_described,deal_completed)
        VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$storeId,$user['id'],$rating,$comment,$meetingId,$feltSafe,$itemAsDesc,$dealDone]);
    // Update store rating
    $avg = q1("SELECT AVG(rating) AS avg FROM cammarket237.reviews WHERE store_id=?", [$storeId]);
    db()->prepare("UPDATE cammarket237.stores SET rating=? WHERE id=?")->execute([round($avg['avg'],1),$storeId]);
    ok(['message' => 'Review submitted! Thank you.']);
}

// ── GET BUYER PROFILE (for sellers) ──────────────────────
if ($action === 'get_buyer_profile') {
    $buyerId = intval(g('buyer_id'));
    $buyer = q1("SELECT id,full_name,region,town,buyer_rating,buyer_review_count,
        deals_completed,no_show_count,buyer_badge,created_at
        FROM cammarket237.users WHERE id=? AND role='buyer'", [$buyerId]);
    if (!$buyer) fail('Buyer not found.');
    $reviews = q("SELECT br.*,u.full_name AS seller_name, st.store_name
        FROM cammarket237.buyer_reviews br
        LEFT JOIN cammarket237.users u ON u.id=br.seller_id
        LEFT JOIN cammarket237.stores st ON st.user_id=br.seller_id
        WHERE br.buyer_id=? ORDER BY br.created_at DESC LIMIT 10", [$buyerId]);
    ok(['buyer' => $buyer, 'reviews' => $reviews]);
}

// ── ADMIN: GET ALL ACTIVE MEETINGS ────────────────────────
if ($action === 'admin_get_meetings') {
    if (p('admin_pass') !== ADMIN_PASS) fail('Unauthorized.');
    $meetings = q("SELECT m.*,
        ub.full_name AS buyer_name, ub.phone AS buyer_phone,
        us.full_name AS seller_name, us.phone AS seller_phone,
        s.store_name
        FROM cammarket237.meetings m
        LEFT JOIN cammarket237.users ub ON ub.id=m.buyer_id
        LEFT JOIN cammarket237.users us ON us.id=m.seller_id
        LEFT JOIN cammarket237.stores s ON s.user_id=m.seller_id
        WHERE m.status IN ('confirmed','in_progress','pending')
        ORDER BY m.scheduled_at DESC");
    ok(['meetings' => $meetings]);
}

// ── SOS ALERT ─────────────────────────────────────────────
if ($action === 'sos_alert') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $lat = floatval(p('lat'));
    $lng = floatval(p('lng'));
    $meetingId = intval(p('meeting_id') ?? 0);
    // Store SOS in notifications for admin
    db()->prepare("INSERT INTO cammarket237.cart_notifications (buyer_id,listing_id,notification_type,message) VALUES (?,?,?,?)")
        ->execute([1, 0, 'sos_alert',
            '🚨 SOS ALERT from '.$user['full_name'].' ('.$user['phone'].') at '.$lat.','.$lng.' - Meeting #'.$meetingId]);
    ok(['message' => 'SOS sent! Help is notified.']);
}


// ═══════════════════════════════════════════════════════════
// BUYER ENQUIRY + SELLER SAFETY APPROVAL SYSTEM
// ═══════════════════════════════════════════════════════════

// ── LOG BUYER ENQUIRY (when buyer contacts seller) ────────
if ($action === 'log_enquiry') {
    $user = authUser();
    $sellerId  = intval(p('seller_id'));
    $listingId = intval(p('listing_id'));
    $itemTitle = p('item_title') ?? '';
    $itemPrice = floatval(p('item_price') ?? 0);
    $buyerName = $user ? ($user['full_name'] ?? '') : 'Guest';
    $buyerPhone = $user ? ($user['phone'] ?? '') : '';
    $buyerId = $user ? $user['id'] : 0;

    try {
        // Store enquiry
        db()->prepare("INSERT INTO cammarket237.enquiries
            (buyer_id, listing_id, store_id, message, buyer_name, buyer_phone, status, created_at)
            VALUES (?, ?, (SELECT id FROM cammarket237.stores WHERE user_id=? LIMIT 1), ?, ?, ?, 'pending', NOW())")
            ->execute([$buyerId, $listingId, $sellerId,
                'Buyer enquiry via CamMarket237 for: '.$itemTitle.' at '.number_format($itemPrice).' FCFA',
                $buyerName, $buyerPhone]);

        // Notify seller
        try {
            db()->prepare("INSERT INTO cammarket237.cart_notifications
                (buyer_id, listing_id, notification_type, message)
                VALUES (?,?,?,?)")
                ->execute([$sellerId, $listingId, 'buyer_enquiry',
                    '💬 New buyer enquiry for "'.$itemTitle.'" from '.$buyerName.' ('.$buyerPhone.')']);
        } catch(Exception $e) {}

        ok(['message' => 'Enquiry logged.']);
    } catch(Exception $e) { ok(['message' => 'Logged.']); }
}

// ── GET SELLER ENQUIRIES ──────────────────────────────────
if ($action === 'get_seller_enquiries') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');
    try {
        $storeId = q1("SELECT id FROM cammarket237.stores WHERE user_id=? LIMIT 1", [$user['id']]);
        if (!$storeId) ok(['enquiries' => []]);
        $enquiries = q("SELECT e.*,
            u.full_name AS buyer_name, u.phone AS buyer_phone,
            u.buyer_rating, u.buyer_review_count, u.buyer_badge,
            l.title AS item_title, l.price AS item_price
            FROM cammarket237.enquiries e
            LEFT JOIN cammarket237.users u ON u.id=e.buyer_id
            LEFT JOIN cammarket237.listings l ON l.id=e.listing_id
            WHERE e.store_id=? AND e.status='pending'
            ORDER BY e.created_at DESC LIMIT 20", [$storeId['id']]);
        ok(['enquiries' => $enquiries]);
    } catch(Exception $e) { ok(['enquiries' => []]); }
}

// ── SELLER APPROVES SAFETY + MARKS AVAILABILITY ──────────
if ($action === 'seller_approve_safety') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');
    $enquiryId   = intval(p('enquiry_id'));
    $buyerId     = intval(p('buyer_id'));
    $listingId   = intval(p('listing_id'));
    $isAvailable = p('is_available') === 'true';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    // Log seller safety acceptance
    try {
        db()->prepare("INSERT INTO cammarket237.safety_acceptances
            (user_id, listing_id, seller_id, ip_address)
            VALUES (?,?,?,?)")
            ->execute([$user['id'], $listingId, $user['id'], $ip]);
    } catch(Exception $e) {}

    // Update enquiry status
    try {
        $status = $isAvailable ? 'available' : 'unavailable';
        db()->prepare("UPDATE cammarket237.enquiries SET status=?, responded_at=NOW() WHERE id=?")
            ->execute([$status, $enquiryId]);
    } catch(Exception $e) {}

    // Notify buyer of response
    try {
        $storeName = q1("SELECT store_name FROM cammarket237.stores WHERE user_id=?", [$user['id']]);
        $msg = $isAvailable
            ? '✅ Great news! '.($storeName['store_name']??'Seller').' confirmed the item is AVAILABLE and approved safety guidelines.'
            : '❌ '.($storeName['store_name']??'Seller').' says the item is no longer available.';
        db()->prepare("INSERT INTO cammarket237.cart_notifications
            (buyer_id, listing_id, notification_type, message) VALUES (?,?,?,?)")
            ->execute([$buyerId, $listingId, 'seller_response', $msg]);
    } catch(Exception $e) {}

    ok(['message' => 'Safety approved and response sent!',
        'is_available' => $isAvailable]);
}

// ── GET BUYER ENQUIRY STATUS ──────────────────────────────
if ($action === 'get_enquiry_status') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $listingId = intval(g('listing_id'));
    $enquiry = q1("SELECT e.*, s.store_name
        FROM cammarket237.enquiries e
        LEFT JOIN cammarket237.stores s ON s.id=e.store_id
        WHERE e.buyer_id=? AND e.listing_id=?
        ORDER BY e.created_at DESC LIMIT 1",
        [$user['id'], $listingId]);
    ok(['enquiry' => $enquiry]);
}


// ═══════════════════════════════════════════════════════════
// FOLLOW STORE + NOTIFICATION ALERTS SYSTEM
// ═══════════════════════════════════════════════════════════

// ── FOLLOW STORE ─────────────────────────────────────────
if ($action === 'follow_store') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $storeId = intval(p('store_id'));
    // Get store owner user_id
    $store = q1("SELECT user_id, store_name FROM cammarket237.stores WHERE id=?", [$storeId]);
    if (!$store) fail('Store not found.');
    $storeOwnerUserId = $store['user_id'];
    if ($storeOwnerUserId == $user['id']) fail('You cannot follow your own store.');
    try {
        db()->prepare("INSERT INTO cammarket237.followers (follower_id, following_id) VALUES (?,?) ON CONFLICT DO NOTHING")
            ->execute([$user['id'], $storeOwnerUserId]);
        // Notify store owner
        try {
            db()->prepare("INSERT INTO cammarket237.cart_notifications (buyer_id,listing_id,notification_type,message) VALUES (?,?,?,?)")
                ->execute([$storeOwnerUserId, 0, 'new_follower',
                    '&#x2665; Someone is now following your store '.$store['store_name'].'!']);
        } catch(Exception $e) {}
        ok(['message' => 'Store followed!', 'store_owner_id' => $storeOwnerUserId]);
    } catch(Exception $e) { fail($e->getMessage()); }
}

// ── UNFOLLOW STORE ────────────────────────────────────────
if ($action === 'unfollow_store') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $storeId = intval(p('store_id'));
    $store = q1("SELECT user_id FROM cammarket237.stores WHERE id=?", [$storeId]);
    if (!$store) fail('Store not found.');
    db()->prepare("DELETE FROM cammarket237.followers WHERE follower_id=? AND following_id=?")
        ->execute([$user['id'], $store['user_id']]);
    ok(['message' => 'Unfollowed.']);
}

// ── SAVE ALERT SETTINGS ───────────────────────────────────
if ($action === 'save_alert_settings') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $settings = p('settings');
    try {
        db()->prepare("UPDATE cammarket237.users SET alert_settings=? WHERE id=?")
            ->execute([$settings, $user['id']]);
    } catch(Exception $e) {
        try {
            db()->prepare("ALTER TABLE cammarket237.users ADD COLUMN IF NOT EXISTS alert_settings TEXT")->execute([]);
            db()->prepare("UPDATE cammarket237.users SET alert_settings=? WHERE id=?")->execute([$settings, $user['id']]);
        } catch(Exception $e2) {}
    }
    ok(['message' => 'Alert settings saved!']);
}

// ── GET FOLLOWED STORES ───────────────────────────────────
if ($action === 'get_followed_stores') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $stores = q("SELECT s.*, f.created_at AS followed_at
        FROM cammarket237.followers f
        JOIN cammarket237.stores s ON s.id=f.following_id
        WHERE f.follower_id=?
        ORDER BY f.created_at DESC", [$user['id']]);
    ok(['stores' => $stores]);
}

// ── NOTIFY FOLLOWERS WHEN NEW ITEM POSTED ────────────────
// Called internally after post_listing
function notifyStoreFollowers($storeId, $listingId, $listingTitle, $price, $category) {
    try {
        $followers = q("SELECT follower_id FROM cammarket237.followers WHERE following_id=?", [$storeId]);
        $store = q1("SELECT store_name FROM cammarket237.stores WHERE id=?", [$storeId]);
        $storeName = $store ? $store['store_name'] : 'A store you follow';
        foreach ($followers as $f) {
            try {
                db()->prepare("INSERT INTO cammarket237.cart_notifications
                    (buyer_id,listing_id,notification_type,message) VALUES (?,?,?,?)")
                    ->execute([$f['follower_id'], $listingId, 'new_listing',
                        '&#x1F195; '.$storeName.' just posted: '.$listingTitle.' - '.number_format($price).' FCFA']);
            } catch(Exception $e) {}
        }
        // Also notify buyers with matching category alerts
        notifyCategoryAlerts($listingId, $listingTitle, $price, $category);
    } catch(Exception $e) {}
}

function notifyCategoryAlerts($listingId, $title, $price, $category) {
    try {
        // Get buyers with alert settings
        $buyers = q("SELECT id, alert_settings FROM cammarket237.users 
            WHERE role='buyer' AND alert_settings IS NOT NULL 
            AND COALESCE(is_active,true)=true");
        foreach ($buyers as $b) {
            try {
                $settings = json_decode($b['alert_settings'], true);
                if (!$settings) continue;
                $cats = $settings['categories'] ?? [];
                $minP = floatval($settings['minPrice'] ?? 0);
                $maxP = floatval($settings['maxPrice'] ?? 0);
                // Check category match
                $catMatch = empty($cats) || in_array($category, $cats);
                // Check price range
                $priceMatch = (!$minP || $price >= $minP) && (!$maxP || $price <= $maxP);
                if ($catMatch && $priceMatch) {
                    db()->prepare("INSERT INTO cammarket237.cart_notifications
                        (buyer_id,listing_id,notification_type,message) VALUES (?,?,?,?)")
                        ->execute([$b['id'], $listingId, 'category_alert',
                            '&#x1F514; New '.$category.': '.$title.' - '.number_format($price).' FCFA']);
                }
            } catch(Exception $e) {}
        }
    } catch(Exception $e) {}
}






// ═══════════════════════════════════════════════════════════
// REFERRAL & INVITE SYSTEM
// ═══════════════════════════════════════════════════════════

if ($action === 'get_referral_stats') {
    $user = authUser();
    if (!$user) fail('Login required.');

    $confirmed = q1(
        "SELECT COALESCE(SUM(reward_fcfa),0) AS total FROM cammarket237.referral_rewards
         WHERE referrer_id=? AND status='confirmed'", [$user['id']]);
    $pending = q1(
        "SELECT COALESCE(SUM(reward_fcfa),0) AS total FROM cammarket237.referral_rewards
         WHERE referrer_id=? AND status='pending'", [$user['id']]);

    $referrals = q(
        "SELECT u.full_name, u.role, u.created_at,
                rr.reward_fcfa, rr.status, rr.confirmed_at
         FROM cammarket237.referral_rewards rr
         JOIN cammarket237.users u ON u.id = rr.referee_id
         WHERE rr.referrer_id=?
         ORDER BY rr.created_at DESC LIMIT 20",
        [$user['id']]
    );

    foreach ($referrals as &$ref) {
        if ($ref['status'] === 'pending' && $ref['role'] === 'seller') {
            $refUser = q1(
                "SELECT u.id FROM cammarket237.users u
                 JOIN cammarket237.referral_rewards rr ON rr.referee_id=u.id
                 WHERE rr.referrer_id=? AND u.full_name=? LIMIT 1",
                [$user['id'], $ref['full_name']]
            );
            if ($refUser) {
                $cnt = q1(
                    "SELECT COUNT(*) AS n FROM cammarket237.listings
                     WHERE store_id IN (SELECT id FROM cammarket237.stores WHERE user_id=?)
                     AND status != 'deleted'",
                    [$refUser['id']]
                );
                $ref['listings_posted'] = intval($cnt['n'] ?? 0);
                $ref['listings_needed'] = 10;
            }
        }
    }
    unset($ref);

    $rankRow = q1(
        "SELECT COUNT(*) + 1 AS rank
         FROM (SELECT referrer_id, COUNT(*) AS cnt FROM cammarket237.referral_rewards GROUP BY referrer_id) t
         WHERE t.cnt > (SELECT COUNT(*) FROM cammarket237.referral_rewards WHERE referrer_id=?)",
        [$user['id']]
    );
    $walletRow = q1("SELECT COALESCE(wallet_balance,0) AS bal FROM cammarket237.users WHERE id=?", [$user['id']]);

    ok([
        'referral_code'   => $user['referral_code'],
        'wallet_balance'  => intval($walletRow['bal'] ?? 0),
        'earned_fcfa'     => intval($confirmed['total'] ?? 0),
        'pending_fcfa'    => intval($pending['total'] ?? 0),
        'total_referrals' => count($referrals),
        'rank'            => $rankRow ? intval($rankRow['rank']) : 1,
        'referrals'       => $referrals,
        'promo_points'    => intval($user['promo_points'] ?? 0),
    ]);
}

if ($action === 'get_wallet_balance') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $row = q1("SELECT COALESCE(wallet_balance,0) AS bal FROM cammarket237.users WHERE id=?", [$user['id']]);
    ok(['wallet_balance' => intval($row['bal'] ?? 0)]);
}



// ═══════════════════════════════════════════════════════════
// DEALS SYSTEM
// ═══════════════════════════════════════════════════════════

if ($action === 'create_deal') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');

    $listingId  = intval(p('listing_id'));
    $discount   = floatval(p('discount_percent'));
    $dealType   = p('deal_type') ?: 'custom';
    $duration   = p('duration') ?: '24h';

    if (!$listingId || $discount <= 0 || $discount > 90) fail('Invalid deal parameters.');

    // Get listing
    $listing = q1("SELECT * FROM cammarket237.listings WHERE id=? AND store_id IN (SELECT id FROM cammarket237.stores WHERE user_id=?)",
        [$listingId, $user['id']]);
    if (!$listing) fail('Listing not found or not yours.');

    $originalPrice = floatval($listing['price']);
    $dealPrice = round($originalPrice * (1 - $discount/100));

    // Calculate end time
    $hours = ['24h'=>24, '3d'=>72, '1w'=>168, '2w'=>336];
    $h = isset($hours[$duration]) ? $hours[$duration] : 24;
    $endsAt = date('Y-m-d H:i:s', strtotime('+'.$h.' hours'));

    // Deactivate existing deal for this listing
    db()->prepare("UPDATE cammarket237.listing_deals SET is_active=false WHERE listing_id=?")->execute([$listingId]);

    // Create new deal
    $store = q1("SELECT id FROM cammarket237.stores WHERE user_id=?", [$user['id']]);
    db()->prepare("INSERT INTO cammarket237.listing_deals
        (listing_id, store_id, seller_id, deal_type, discount_percent, original_price, deal_price, ends_at)
        VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$listingId, $store['id'], $user['id'], $dealType, $discount, $originalPrice, $dealPrice, $endsAt]);

    // Update listing price to deal price
    db()->prepare("UPDATE cammarket237.listings SET price=?, original_price=?, price_drop_active=true WHERE id=?")
        ->execute([$dealPrice, $originalPrice, $listingId]);

    ok(['message' => 'Deal created!', 'deal_price' => $dealPrice, 'ends_at' => $endsAt, 'saves' => ($originalPrice - $dealPrice)]);
}

if ($action === 'end_deal') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');
    $listingId = intval(p('listing_id'));
    $deal = q1("SELECT * FROM cammarket237.listing_deals WHERE listing_id=? AND seller_id=? AND is_active=true",
        [$listingId, $user['id']]);
    if (!$deal) fail('No active deal found.');
    // Restore original price
    db()->prepare("UPDATE cammarket237.listings SET price=?, price_drop_active=false WHERE id=?")->execute([intval($deal['original_price']), $listingId]);
    db()->prepare("UPDATE cammarket237.listing_deals SET is_active=false WHERE id=?")->execute([$deal['id']]);
    ok(['message' => 'Deal ended. Price restored.']);
}

if ($action === 'get_deals') {
    // Auto-expire old deals first
    try {
        $expired = q("SELECT ld.listing_id, ld.original_price FROM cammarket237.listing_deals ld
            WHERE ld.is_active=true AND ld.ends_at < NOW()");
        foreach ($expired as $ex) {
            db()->prepare("UPDATE cammarket237.listings SET price=?, price_drop_active=false WHERE id=?")
                ->execute([$ex['original_price'], $ex['listing_id']]);
            db()->prepare("UPDATE cammarket237.listing_deals SET is_active=false WHERE listing_id=? AND is_active=true")
                ->execute([$ex['listing_id']]);
        }
    } catch(Exception $e) {}

    $deals = q("SELECT ld.*, l.title, l.category, l.town,
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
        LIMIT 20");

    ok(['deals' => $deals]);
}

if ($action === 'get_my_deals') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $deals = q("SELECT ld.*, l.title, lm.media_url AS main_photo,
        EXTRACT(EPOCH FROM (ld.ends_at - NOW())) AS seconds_left
        FROM cammarket237.listing_deals ld
        JOIN cammarket237.listings l ON l.id=ld.listing_id
        LEFT JOIN cammarket237.listing_media lm ON lm.listing_id=l.id AND lm.media_role='main_image'
        WHERE ld.seller_id=? AND ld.is_active=true
        ORDER BY ld.created_at DESC", [$user['id']]);
    ok(['deals' => $deals]);
}



// ── NEW ITEMS NOTIFICATIONS ───────────────────────────────

if ($action === 'get_new_items_count') {
    $hours = 48;
    $row = q1("SELECT COUNT(*) AS cnt FROM cammarket237.listings l
        JOIN cammarket237.stores s ON s.id=l.store_id
        WHERE l.status='active'
        AND l.created_at > NOW() - INTERVAL '48 hours'
        AND COALESCE(l.moderation_status,'approved')='approved'");
    ok(['count' => intval($row['cnt'])]);
}

if ($action === 'get_new_items') {
    $hours = 48;
    $town   = g('town') ?: '';
    $region = g('region') ?: '';

    $where = ["l.status='active'",
              "l.created_at > NOW() - INTERVAL '" . $hours . " hours'",
              "COALESCE(l.moderation_status,'approved')='approved'"];
    $params = [];

    if ($town)   { $where[] = "l.town=?";   $params[] = $town; }
    if ($region) { $where[] = "l.region=?"; $params[] = $region; }

    $wClause = implode(" AND ", $where);

    $listings = q("SELECT l.id, l.title, l.price, l.category, l.town,
        lm.media_url AS main_photo, l.condition, l.created_at,
        s.store_name, s.whatsapp, s.rating as store_rating,
        u.full_name as seller_name
        FROM cammarket237.listings l
        JOIN cammarket237.stores s ON s.id=l.store_id
        JOIN cammarket237.users u ON u.id=s.user_id
        LEFT JOIN cammarket237.listing_media lm ON lm.listing_id=l.id AND lm.media_role='main_image'
        WHERE $wClause
        ORDER BY l.created_at DESC
        LIMIT 30", $params);

    ok(['listings' => $listings, 'hours' => $hours]);
}



// ═══════════════════════════════════════════════════════════
// FORGOT PASSWORD FLOW
// ═══════════════════════════════════════════════════════════

if ($action === 'forgot_password_otp') {
    $phone = trim(p('phone'));
    $role  = p('role') ?: 'buyer';
    if (!$phone) fail('Phone number required.');
    $user = findUserByPhone($phone, $role);
    if (!$user) fail('No ' . $role . ' account found with this phone number.');
    // Generate 6-digit OTP
    $otp = str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    $exp = date('Y-m-d H:i:s', time() + 600); // 10 mins
    // Store OTP
    try {
        db()->prepare("DELETE FROM cammarket237.otp_tokens WHERE phone=?")->execute([$user['phone']]);
        db()->prepare("INSERT INTO cammarket237.otp_tokens (phone, token, purpose, expires_at) VALUES (?,?,'reset',?)")
            ->execute([$user['phone'], $otp, $exp]);
    } catch(Exception $e) {
        try {
            db()->prepare("ALTER TABLE cammarket237.otp_tokens ADD COLUMN IF NOT EXISTS purpose VARCHAR(20) DEFAULT 'verify'")->execute([]);
            db()->prepare("INSERT INTO cammarket237.otp_tokens (phone, token, purpose, expires_at) VALUES (?,?,'reset',?)")
                ->execute([$user['phone'], $otp, $exp]);
        } catch(Exception $e2) {}
    }
    $resp = ['message' => 'OTP sent! Check your WhatsApp.', 'expires_in' => '10 minutes'];
    if (file_exists('/.dockerenv')) $resp['otp'] = $otp;
    ok($resp);
}

if ($action === 'reset_password') {
    $phone   = trim(p('phone'));
    $role    = p('role') ?: 'buyer';
    $otp     = trim(p('otp'));
    $newpass = p('new_password');
    if (!$phone || !$otp || !$newpass) fail('Missing required fields.');
    if (strlen($newpass) < 6) fail('Password must be at least 6 characters.');
    $user = findUserByPhone($phone, $role);
    if (!$user) fail('Account not found.');
    // Verify OTP
    $otpRow = q1("SELECT * FROM cammarket237.otp_tokens WHERE phone=? AND token=? AND purpose='reset' AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1",
        [$user['phone'], $otp]);
    if (!$otpRow) fail('Invalid or expired OTP. Please request a new one.');
    // Update password
    $hash = password_hash($newpass, PASSWORD_DEFAULT);
    db()->prepare("UPDATE cammarket237.users SET password_hash=? WHERE id=?")->execute([$hash, $user['id']]);
    // Delete used OTP
    db()->prepare("DELETE FROM cammarket237.otp_tokens WHERE phone=?")->execute([$user['phone']]);
    // Clear rate limits
    resetRateLimit('*', $role . '_login');
    ok(['message' => 'Password reset successfully! Please login with your new password.']);
}



// ── UPDATE STORE LOCATION ─────────────────────────────────
if ($action === 'update_store_location') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');
    $lat = floatval(p('lat'));
    $lng = floatval(p('lng'));
    if (!$lat || !$lng) fail('Invalid coordinates.');
    try {
        db()->prepare("UPDATE cammarket237.stores SET latitude=?, longitude=?, location_verified=true, location_updated_at=NOW() WHERE user_id=?")
            ->execute([$lat, $lng, $user['id']]);
        ok(['message' => 'Store location updated!', 'lat' => $lat, 'lng' => $lng]);
    } catch(Exception $e) { fail($e->getMessage()); }
}



// ═══════════════════════════════════════════════════════════
// REFERRAL & INVITE SYSTEM
// ═══════════════════════════════════════════════════════════

if ($action === 'get_referral_stats') {
    $user = authUser();
    if (!$user) fail('Login required.');

    $confirmed = q1(
        "SELECT COALESCE(SUM(reward_fcfa),0) AS total FROM cammarket237.referral_rewards
         WHERE referrer_id=? AND status='confirmed'", [$user['id']]);
    $pending = q1(
        "SELECT COALESCE(SUM(reward_fcfa),0) AS total FROM cammarket237.referral_rewards
         WHERE referrer_id=? AND status='pending'", [$user['id']]);

    $referrals = q(
        "SELECT u.full_name, u.role, u.created_at,
                rr.reward_fcfa, rr.status, rr.confirmed_at
         FROM cammarket237.referral_rewards rr
         JOIN cammarket237.users u ON u.id = rr.referee_id
         WHERE rr.referrer_id=?
         ORDER BY rr.created_at DESC LIMIT 20",
        [$user['id']]
    );

    foreach ($referrals as &$ref) {
        if ($ref['status'] === 'pending' && $ref['role'] === 'seller') {
            $refUser = q1(
                "SELECT u.id FROM cammarket237.users u
                 JOIN cammarket237.referral_rewards rr ON rr.referee_id=u.id
                 WHERE rr.referrer_id=? AND u.full_name=? LIMIT 1",
                [$user['id'], $ref['full_name']]
            );
            if ($refUser) {
                $cnt = q1(
                    "SELECT COUNT(*) AS n FROM cammarket237.listings
                     WHERE store_id IN (SELECT id FROM cammarket237.stores WHERE user_id=?)
                     AND status != 'deleted'",
                    [$refUser['id']]
                );
                $ref['listings_posted'] = intval($cnt['n'] ?? 0);
                $ref['listings_needed'] = 10;
            }
        }
    }
    unset($ref);

    $rankRow = q1(
        "SELECT COUNT(*) + 1 AS rank
         FROM (SELECT referrer_id, COUNT(*) AS cnt FROM cammarket237.referral_rewards GROUP BY referrer_id) t
         WHERE t.cnt > (SELECT COUNT(*) FROM cammarket237.referral_rewards WHERE referrer_id=?)",
        [$user['id']]
    );
    $walletRow = q1("SELECT COALESCE(wallet_balance,0) AS bal FROM cammarket237.users WHERE id=?", [$user['id']]);

    ok([
        'referral_code'   => $user['referral_code'],
        'wallet_balance'  => intval($walletRow['bal'] ?? 0),
        'earned_fcfa'     => intval($confirmed['total'] ?? 0),
        'pending_fcfa'    => intval($pending['total'] ?? 0),
        'total_referrals' => count($referrals),
        'rank'            => $rankRow ? intval($rankRow['rank']) : 1,
        'referrals'       => $referrals,
        'promo_points'    => intval($user['promo_points'] ?? 0),
    ]);
}

if ($action === 'get_wallet_balance') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $row = q1("SELECT COALESCE(wallet_balance,0) AS bal FROM cammarket237.users WHERE id=?", [$user['id']]);
    ok(['wallet_balance' => intval($row['bal'] ?? 0)]);
}



// ═══════════════════════════════════════════════════════════
// DEALS SYSTEM
// ═══════════════════════════════════════════════════════════

if ($action === 'create_deal') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');

    $listingId  = intval(p('listing_id'));
    $discount   = floatval(p('discount_percent'));
    $dealType   = p('deal_type') ?: 'custom';
    $duration   = p('duration') ?: '24h';

    if (!$listingId || $discount <= 0 || $discount > 90) fail('Invalid deal parameters.');

    // Get listing
    $listing = q1("SELECT * FROM cammarket237.listings WHERE id=? AND store_id IN (SELECT id FROM cammarket237.stores WHERE user_id=?)",
        [$listingId, $user['id']]);
    if (!$listing) fail('Listing not found or not yours.');

    $originalPrice = floatval($listing['price']);
    $dealPrice = round($originalPrice * (1 - $discount/100));

    // Calculate end time
    $hours = ['24h'=>24, '3d'=>72, '1w'=>168, '2w'=>336];
    $h = isset($hours[$duration]) ? $hours[$duration] : 24;
    $endsAt = date('Y-m-d H:i:s', strtotime('+'.$h.' hours'));

    // Deactivate existing deal for this listing
    db()->prepare("UPDATE cammarket237.listing_deals SET is_active=false WHERE listing_id=?")->execute([$listingId]);

    // Create new deal
    $store = q1("SELECT id FROM cammarket237.stores WHERE user_id=?", [$user['id']]);
    db()->prepare("INSERT INTO cammarket237.listing_deals
        (listing_id, store_id, seller_id, deal_type, discount_percent, original_price, deal_price, ends_at)
        VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$listingId, $store['id'], $user['id'], $dealType, $discount, $originalPrice, $dealPrice, $endsAt]);

    // Update listing price to deal price
    db()->prepare("UPDATE cammarket237.listings SET price=?, original_price=?, price_drop_active=true WHERE id=?")
        ->execute([$dealPrice, $originalPrice, $listingId]);

    ok(['message' => 'Deal created!', 'deal_price' => $dealPrice, 'ends_at' => $endsAt, 'saves' => ($originalPrice - $dealPrice)]);
}

if ($action === 'end_deal') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');
    $listingId = intval(p('listing_id'));
    $deal = q1("SELECT * FROM cammarket237.listing_deals WHERE listing_id=? AND seller_id=? AND is_active=true",
        [$listingId, $user['id']]);
    if (!$deal) fail('No active deal found.');
    // Restore original price
    db()->prepare("UPDATE cammarket237.listings SET price=?, price_drop_active=false WHERE id=?")->execute([intval($deal['original_price']), $listingId]);
    db()->prepare("UPDATE cammarket237.listing_deals SET is_active=false WHERE id=?")->execute([$deal['id']]);
    ok(['message' => 'Deal ended. Price restored.']);
}

if ($action === 'get_deals') {
    // Auto-expire old deals first
    try {
        $expired = q("SELECT ld.listing_id, ld.original_price FROM cammarket237.listing_deals ld
            WHERE ld.is_active=true AND ld.ends_at < NOW()");
        foreach ($expired as $ex) {
            db()->prepare("UPDATE cammarket237.listings SET price=?, price_drop_active=false WHERE id=?")
                ->execute([$ex['original_price'], $ex['listing_id']]);
            db()->prepare("UPDATE cammarket237.listing_deals SET is_active=false WHERE listing_id=? AND is_active=true")
                ->execute([$ex['listing_id']]);
        }
    } catch(Exception $e) {}

    $deals = q("SELECT ld.*, l.title, l.category, l.town,
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
        LIMIT 20");

    ok(['deals' => $deals]);
}

if ($action === 'get_my_deals') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $deals = q("SELECT ld.*, l.title, lm.media_url AS main_photo,
        EXTRACT(EPOCH FROM (ld.ends_at - NOW())) AS seconds_left
        FROM cammarket237.listing_deals ld
        JOIN cammarket237.listings l ON l.id=ld.listing_id
        LEFT JOIN cammarket237.listing_media lm ON lm.listing_id=l.id AND lm.media_role='main_image'
        WHERE ld.seller_id=? AND ld.is_active=true
        ORDER BY ld.created_at DESC", [$user['id']]);
    ok(['deals' => $deals]);
}



// ── NEW ITEMS NOTIFICATIONS ───────────────────────────────

if ($action === 'get_new_items_count') {
    $hours = 48;
    $row = q1("SELECT COUNT(*) AS cnt FROM cammarket237.listings l
        JOIN cammarket237.stores s ON s.id=l.store_id
        WHERE l.status='active'
        AND l.created_at > NOW() - INTERVAL '" . $hours . " hours'
        AND COALESCE(l.moderation_status,'approved')='approved'");
    ok(['count' => intval($row['cnt'])]);
}

if ($action === 'get_new_items') {
    $hours = 48;
    $town   = g('town') ?: '';
    $region = g('region') ?: '';

    $where = ["l.status='active'",
              "l.created_at > NOW() - INTERVAL '" . $hours . " hours'",
              "COALESCE(l.moderation_status,'approved')='approved'"];
    $params = [];

    if ($town)   { $where[] = "l.town=?";   $params[] = $town; }
    if ($region) { $where[] = "l.region=?"; $params[] = $region; }

    $wClause = implode(" AND ", $where);

    $listings = q("SELECT l.id, l.title, l.price, l.category, l.town,
        lm.media_url AS main_photo, l.condition, l.created_at,
        s.store_name, s.whatsapp, s.rating as store_rating,
        u.full_name as seller_name
        FROM cammarket237.listings l
        JOIN cammarket237.stores s ON s.id=l.store_id
        JOIN cammarket237.users u ON u.id=s.user_id
        LEFT JOIN cammarket237.listing_media lm ON lm.listing_id=l.id AND lm.media_role='main_image'
        WHERE $wClause
        ORDER BY l.created_at DESC
        LIMIT 30", $params);

    ok(['listings' => $listings, 'hours' => $hours]);
}



// ── TOGGLE STOCK STATUS ───────────────────────────────
if ($action === 'toggle_stock') {
    $user = authUser();
    if (!$user || $user['role'] !== 'seller') fail('Sellers only.');
    $listingId  = intval(p('listing_id'));
    $newStatus  = p('stock_status');
    $allowed    = ['in_stock', 'out_of_stock', 'coming_soon'];
    if (!in_array($newStatus, $allowed)) fail('Invalid stock status.');
    // Verify listing belongs to this seller
    $listing = q1("SELECT id FROM cammarket237.listings WHERE id=? AND store_id IN (SELECT id FROM cammarket237.stores WHERE user_id=?)", [$listingId, $user['id']]);
    if (!$listing) fail('Listing not found or not yours.');
    db()->prepare("UPDATE cammarket237.listings SET stock_status=?, updated_at=NOW() WHERE id=?")->execute([$newStatus, $listingId]);
    ok(['message' => 'Stock status updated to: ' . $newStatus, 'stock_status' => $newStatus]);
}



// ═══════════════════════════════════════════════════════════
// RECOVERY PIN SYSTEM
// ═══════════════════════════════════════════════════════════

// Set or update recovery PIN (during registration or settings)
if ($action === 'set_recovery_pin') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $newPin = trim(p('new_pin'));
    $oldPin = trim(p('old_pin') ?: '');

    // Validate new PIN
    if (!preg_match('/^[0-9]{6}$/', $newPin)) fail('PIN must be exactly 6 digits.');

    // Block weak PINs
    $weak = ['000000','111111','222222','333333','444444','555555','666666','777777','888888','999999',
            '123456','654321','121212','112233','123123','000123','111222'];
    if (in_array($newPin, $weak)) fail('Please choose a less obvious PIN.');

    // If user already has PIN, verify old PIN
    $hasPinUser = q1("SELECT recovery_pin_hash FROM cammarket237.users WHERE id=?", [$user['id']]);
    if (!empty($hasPinUser['recovery_pin_hash'])) {
        if (!$oldPin) fail('Please enter your current PIN to change it.');
        if (!password_verify($oldPin, $hasPinUser['recovery_pin_hash'])) fail('Current PIN is incorrect.');
    }

    // Hash and save
    $hash = password_hash($newPin, PASSWORD_DEFAULT);
    db()->prepare("UPDATE cammarket237.users SET recovery_pin_hash=?, pin_set_at=NOW() WHERE id=?")
        ->execute([$hash, $user['id']]);

    ok(['message' => 'Recovery PIN saved! Keep it safe.']);
}

// Verify PIN for password reset (no auth needed)
if ($action === 'verify_recovery_pin') {
    $phone = trim(p('phone'));
    $role  = p('role') ?: 'buyer';
    $pin   = trim(p('pin'));

    if (!$phone || !$pin) fail('Phone and PIN required.');
    if (!preg_match('/^[0-9]{6}$/', $pin)) fail('Invalid PIN format.');

    // Rate limit: 3 attempts per 30 minutes
    $rateKey = 'pin_reset_' . preg_replace('/[^0-9]/', '', $phone);
    if (function_exists('checkRateLimit')) {
        $rateOk = checkRateLimit($rateKey, 'pin_reset', 3, 1800);
        if (!$rateOk['allowed']) fail('Too many failed PIN attempts. Try again in ' . ($rateOk['wait_minutes'] ?? 30) . ' minute(s).');
    }

    $user = findUserByPhone($phone, $role);
    if (!$user) fail('No ' . $role . ' account found.');

    if (empty($user['recovery_pin_hash'])) {
        fail('No recovery PIN set on this account. Contact support.');
    }

    if (!password_verify($pin, $user['recovery_pin_hash'])) {
        fail('Incorrect PIN. Please try again.');
    }

    // Generate one-time reset token (valid 10 minutes)
    $resetToken = bin2hex(random_bytes(32));
    db()->prepare("UPDATE cammarket237.users SET session_token=?, session_expires_at=NOW() + INTERVAL '10 minutes' WHERE id=?")
        ->execute([$resetToken, $user['id']]);

    // Reset rate limit on success
    if (function_exists('resetRateLimit')) resetRateLimit($rateKey, 'pin_reset');

    ok(['message' => 'PIN verified. You can now set a new password.', 'reset_token' => $resetToken]);
}

// Reset password using verified token
if ($action === 'reset_password_with_pin') {
    $token   = trim(p('reset_token'));
    $newpass = p('new_password');

    if (!$token || !$newpass) fail('Missing required fields.');
    if (strlen($newpass) < 6) fail('Password must be at least 6 characters.');

    $user = q1("SELECT * FROM cammarket237.users WHERE session_token=? AND session_expires_at > NOW() LIMIT 1", [$token]);
    if (!$user) fail('Reset session expired. Please verify PIN again.');

    $hash = password_hash($newpass, PASSWORD_DEFAULT);
    db()->prepare("UPDATE cammarket237.users SET password_hash=?, session_token=NULL, session_expires_at=NULL, password_changed_at=NOW() WHERE id=?")
        ->execute([$hash, $user['id']]);

    ok(['message' => 'Password reset successfully! Please login.']);
}

// Check if user has PIN set
if ($action === 'check_pin_status') {
    $user = authUser();
    if (!$user) fail('Login required.');
    $row = q1("SELECT recovery_pin_hash FROM cammarket237.users WHERE id=?", [$user['id']]);
    ok(['has_pin' => !empty($row['recovery_pin_hash'])]);
}

