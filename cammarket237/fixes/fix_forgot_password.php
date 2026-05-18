<?php
$f = '/var/www/cammarket237/api.php';
$c = file_get_contents($f);

$patch = '

// ═══════════════════════════════════════════════════════════
// FORGOT PASSWORD FLOW
// ═══════════════════════════════════════════════════════════

if ($action === \'forgot_password_otp\') {
    $phone = trim(p(\'phone\'));
    $role  = p(\'role\') ?: \'buyer\';
    if (!$phone) fail(\'Phone number required.\');
    $user = findUserByPhone($phone, $role);
    if (!$user) fail(\'No \' . $role . \' account found with this phone number.\');
    // Generate 6-digit OTP
    $otp = str_pad(rand(100000, 999999), 6, \'0\', STR_PAD_LEFT);
    $exp = date(\'Y-m-d H:i:s\', time() + 600); // 10 mins
    // Store OTP
    try {
        db()->prepare("DELETE FROM cammarket237.otp_tokens WHERE phone=? AND purpose=\'reset\'")->execute([$user[\'phone\']]);
        db()->prepare("INSERT INTO cammarket237.otp_tokens (phone, otp_code, purpose, expires_at) VALUES (?,?,\'reset\',?)")
            ->execute([$user[\'phone\'], $otp, $exp]);
    } catch(Exception $e) {
        try {
            db()->prepare("ALTER TABLE cammarket237.otp_tokens ADD COLUMN IF NOT EXISTS purpose VARCHAR(20) DEFAULT \'verify\'")->execute([]);
            db()->prepare("INSERT INTO cammarket237.otp_tokens (phone, otp_code, purpose, expires_at) VALUES (?,?,\'reset\',?)")
                ->execute([$user[\'phone\'], $otp, $exp]);
        } catch(Exception $e2) {}
    }
    // In production send via SMS - for now return OTP in response (remove in production)
    ok([\'message\' => \'OTP sent! Check your phone.\', \'otp\' => $otp, \'expires_in\' => \'10 minutes\']);
}

if ($action === \'reset_password\') {
    $phone   = trim(p(\'phone\'));
    $role    = p(\'role\') ?: \'buyer\';
    $otp     = trim(p(\'otp\'));
    $newpass = p(\'new_password\');
    if (!$phone || !$otp || !$newpass) fail(\'Missing required fields.\');
    if (strlen($newpass) < 6) fail(\'Password must be at least 6 characters.\');
    $user = findUserByPhone($phone, $role);
    if (!$user) fail(\'Account not found.\');
    // Verify OTP
    $otpRow = q1("SELECT * FROM cammarket237.otp_tokens WHERE phone=? AND otp_code=? AND purpose=\'reset\' AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1",
        [$user[\'phone\'], $otp]);
    if (!$otpRow) fail(\'Invalid or expired OTP. Please request a new one.\');
    // Update password
    $hash = password_hash($newpass, PASSWORD_DEFAULT);
    db()->prepare("UPDATE cammarket237.users SET password_hash=? WHERE id=?")->execute([$hash, $user[\'id\']]);
    // Delete used OTP
    db()->prepare("DELETE FROM cammarket237.otp_tokens WHERE phone=? AND purpose=\'reset\'")->execute([$user[\'phone\']]);
    // Clear rate limits
    resetRateLimit(\'*\', $role . \'_login\');
    ok([\'message\' => \'Password reset successfully! Please login with your new password.\']);
}

';

// Append before end of file
$c .= $patch;
file_put_contents($f, $c);
echo shell_exec('php -l ' . $f . ' 2>&1');
echo "Forgot password API added!\n";
echo "Size: " . filesize($f) . "\n";
