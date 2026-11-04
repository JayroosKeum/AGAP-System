<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../models/User.php';

function assertTest(bool $condition, string $message): void {
    if (!$condition) {
        echo "  [FAIL] {$message}\n";
        throw new RuntimeException("Assertion failed: {$message}");
    }
    echo "  [PASS] {$message}\n";
}

echo "====================================================================\n";
echo "   FORGOT PASSWORD & GMAIL VERIFICATION TEST SUITE\n";
echo "====================================================================\n\n";

$auth = new AuthController();
$userModel = new User();
$db = (new Database())->connect();

// 1. Test request reset via username
echo "[1] Requesting password reset by username ('clerk')...\n";
$reqResult = $auth->requestPasswordReset(['identity' => 'clerk']);
assertTest($reqResult['success'] === true, "Password reset request returned success = true");
assertTest(!empty($reqResult['masked_email']), "Masked email returned: " . ($reqResult['masked_email'] ?? ''));
assertTest(str_contains($reqResult['masked_email'], '@agap.test'), "Masked email contains domain @agap.test");

// 2. Verify token was created in DB
echo "\n[2] Checking database password_reset_tokens table...\n";
$user = $userModel->findByUsername('clerk');
assertTest($user !== false, "Found clerk user record in database");

$stmt = $db->prepare('SELECT * FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL ORDER BY reset_id DESC LIMIT 1');
$stmt->execute([(int)$user['user_id']]);
$tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);
assertTest($tokenRow !== false, "Active reset token exists in database");
assertTest(strlen($tokenRow['token_hash']) === 64, "Token hash is SHA-256 (64 hex characters)");

// 3. Test token validity helper
echo "\n[3] Testing token validity check...\n";
assertTest(!$auth->isPasswordResetTokenValid('invalid-token-string'), "Invalid token string is rejected");
assertTest(!$auth->isPasswordResetTokenValid(str_repeat('0', 64)), "Non-existent 64-char token is rejected");

// Generate a test token directly to test reset workflow
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$expiresAt = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
$created = $userModel->createPasswordResetToken((int)$user['user_id'], $tokenHash, $expiresAt);
assertTest($created === true, "Manual token creation succeeded");
assertTest($auth->isPasswordResetTokenValid($rawToken), "isPasswordResetTokenValid returns true for newly created token");

// 4. Test validation errors on reset
echo "\n[4] Testing validation constraints on password reset...\n";
$mismatchResult = $auth->resetPassword([
    'token' => $rawToken,
    'password' => 'SecurePass1234!',
    'password_confirmation' => 'DifferentPass123!'
]);
assertTest($mismatchResult['success'] === false, "Password confirmation mismatch rejected");

$shortResult = $auth->resetPassword([
    'token' => $rawToken,
    'password' => 'Short1!',
    'password_confirmation' => 'Short1!'
]);
assertTest($shortResult['success'] === false, "Short password (<12 chars) rejected");

// 5. Test valid reset
echo "\n[5] Executing valid password reset...\n";
$newPass = 'StrongNewPass2026!';
$resetSuccess = $auth->resetPassword([
    'token' => $rawToken,
    'password' => $newPass,
    'password_confirmation' => $newPass
]);
assertTest($resetSuccess['success'] === true, "Password reset succeeded");
assertTest($resetSuccess['user_id'] === (int)$user['user_id'], "Returned matching user_id");

// 6. Test login with newly set password
echo "\n[6] Testing login with new password...\n";
assertTest($auth->login('clerk', $newPass) === true, "Login succeeded with newly updated password");

// 7. Verify token cannot be reused
echo "\n[7] Verifying one-time token cannot be reused...\n";
$reusedResult = $auth->resetPassword([
    'token' => $rawToken,
    'password' => 'AnotherPass9999!',
    'password_confirmation' => 'AnotherPass9999!'
]);
assertTest($reusedResult['success'] === false, "Reused token correctly rejected");
assertTest(!$auth->isPasswordResetTokenValid($rawToken), "Consumed token is no longer valid");

// 8. Restore default test password
echo "\n[8] Restoring default password for development...\n";
$db->prepare("UPDATE users SET password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' WHERE username = 'clerk'")->execute();
assertTest($auth->login('clerk', 'password') === true, "Restored default password verified");

// 9. Non-existent username
echo "\n[9] Testing unknown username recovery request...\n";
$unknownResult = $auth->requestPasswordReset(['identity' => 'nonexistent_test_user']);
assertTest($unknownResult['success'] === true, "Unknown username returns standard privacy-preserving response");

echo "\n====================================================================\n";
echo "   ALL FORGOT PASSWORD TESTS PASSED SUCCESSFULLY!\n";
echo "====================================================================\n";
