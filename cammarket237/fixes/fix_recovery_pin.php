<?php
// Recovery PIN System - replaces OTP-based password reset
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);
$db_host = file_exists('/.dockerenv') ? 'db' : 'localhost';

// 1. Add recovery_pin_hash column
try {
    $pdo = new PDO("pgsql:host=$db_host;dbname=cammarket237_db", 'cammarket_user', 'CamMarket2024');
    $pdo->exec("
        ALTER TABLE cammarket237.users
        ADD COLUMN IF NOT EXISTS recovery_pin_hash VARCHAR(255) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS pin_set_at TIMESTAMP DEFAULT NULL;
    ");
    echo "Added recovery_pin_hash column!\n";
} catch(Exception $e) {
    echo "DB: " . $e->getMessage() . "\n";
}

// 2. Add API endpoints
$patch = '

// ═══════════════════════════════════════════════════════════
// RECOVERY PIN SYSTEM
// ═══════════════════════════════════════════════════════════

// Set or update recovery PIN (during registration or settings)
if ($action === \'set_recovery_pin\') {
    $user = authUser();
    if (!$user) fail(\'Login required.\');
    $newPin = trim(p(\'new_pin\'));
    $oldPin = trim(p(\'old_pin\') ?: \'\');

    // Validate new PIN
    if (!preg_match(\'/^[0-9]{6}$/\', $newPin)) fail(\'PIN must be exactly 6 digits.\');

    // Block weak PINs
    $weak = [\'000000\',\'111111\',\'222222\',\'333333\',\'444444\',\'555555\',\'666666\',\'777777\',\'888888\',\'999999\',
            \'123456\',\'654321\',\'121212\',\'112233\',\'123123\',\'000123\',\'111222\'];
    if (in_array($newPin, $weak)) fail(\'Please choose a less obvious PIN.\');

    // If user already has PIN, verify old PIN
    $hasPinUser = q1("SELECT recovery_pin_hash FROM cammarket237.users WHERE id=?", [$user[\'id\']]);
    if (!empty($hasPinUser[\'recovery_pin_hash\'])) {
        if (!$oldPin) fail(\'Please enter your current PIN to change it.\');
        if (!password_verify($oldPin, $hasPinUser[\'recovery_pin_hash\'])) fail(\'Current PIN is incorrect.\');
    }

    // Hash and save
    $hash = password_hash($newPin, PASSWORD_DEFAULT);
    db()->prepare("UPDATE cammarket237.users SET recovery_pin_hash=?, pin_set_at=NOW() WHERE id=?")
        ->execute([$hash, $user[\'id\']]);

    ok([\'message\' => \'Recovery PIN saved! Keep it safe.\']);
}

// Verify PIN for password reset (no auth needed)
if ($action === \'verify_recovery_pin\') {
    $phone = trim(p(\'phone\'));
    $role  = p(\'role\') ?: \'buyer\';
    $pin   = trim(p(\'pin\'));

    if (!$phone || !$pin) fail(\'Phone and PIN required.\');
    if (!preg_match(\'/^[0-9]{6}$/\', $pin)) fail(\'Invalid PIN format.\');

    // Rate limit: 3 attempts per 30 minutes
    $rateKey = \'pin_reset_\' . preg_replace(\'/[^0-9]/\', \'\', $phone);
    if (function_exists(\'checkRateLimit\')) {
        $rateOk = checkRateLimit($rateKey, 3, 1800);
        if (!$rateOk) fail(\'Too many failed PIN attempts. Try again in 30 minutes.\');
    }

    $user = findUserByPhone($phone, $role);
    if (!$user) fail(\'No \' . $role . \' account found.\');

    if (empty($user[\'recovery_pin_hash\'])) {
        fail(\'No recovery PIN set on this account. Contact support.\');
    }

    if (!password_verify($pin, $user[\'recovery_pin_hash\'])) {
        fail(\'Incorrect PIN. Please try again.\');
    }

    // Generate one-time reset token (valid 10 minutes)
    $resetToken = bin2hex(random_bytes(32));
    db()->prepare("UPDATE cammarket237.users SET session_token=?, session_expires_at=NOW() + INTERVAL \'10 minutes\' WHERE id=?")
        ->execute([$resetToken, $user[\'id\']]);

    // Reset rate limit on success
    if (function_exists(\'resetRateLimit\')) resetRateLimit($rateKey, \'\');

    ok([\'message\' => \'PIN verified. You can now set a new password.\', \'reset_token\' => $resetToken]);
}

// Reset password using verified token
if ($action === \'reset_password_with_pin\') {
    $token   = trim(p(\'reset_token\'));
    $newpass = p(\'new_password\');

    if (!$token || !$newpass) fail(\'Missing required fields.\');
    if (strlen($newpass) < 6) fail(\'Password must be at least 6 characters.\');

    $user = q1("SELECT * FROM cammarket237.users WHERE session_token=? AND session_expires_at > NOW() LIMIT 1", [$token]);
    if (!$user) fail(\'Reset session expired. Please verify PIN again.\');

    $hash = password_hash($newpass, PASSWORD_DEFAULT);
    db()->prepare("UPDATE cammarket237.users SET password_hash=?, session_token=NULL, session_expires_at=NULL, password_changed_at=NOW() WHERE id=?")
        ->execute([$hash, $user[\'id\']]);

    ok([\'message\' => \'Password reset successfully! Please login.\']);
}

// Check if user has PIN set
if ($action === \'check_pin_status\') {
    $user = authUser();
    if (!$user) fail(\'Login required.\');
    $row = q1("SELECT recovery_pin_hash FROM cammarket237.users WHERE id=?", [$user[\'id\']]);
    ok([\'has_pin\' => !empty($row[\'recovery_pin_hash\'])]);
}

';

$c .= $patch;
file_put_contents($f, $c);
echo shell_exec('php -l ' . $f . ' 2>&1');
echo "Recovery PIN API added!\n";
echo "Size: " . filesize($f) . "\n";
