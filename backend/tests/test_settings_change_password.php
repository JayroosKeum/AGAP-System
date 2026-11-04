<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../controllers/SettingsController.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../models/User.php';

function runCheck(bool $condition, string $message): void {
    if (!$condition) {
        echo "  [FAIL] {$message}\n";
        throw new RuntimeException("Assertion failed: {$message}");
    }
    echo "  [PASS] {$message}\n";
}

echo "====================================================================\n";
echo "   SETTINGS CHANGE PASSWORD FOR ALL ROLES TEST\n";
echo "====================================================================\n\n";

$db = (new Database())->connect();
$settingsController = new SettingsController();
$auth = new AuthController();
$userModel = new User();

// Roles to test: 1: Admin, 2: Clerk, 3: Lupon Member, 4: Summons Server
$testRoles = [
    1 => 'admin',
    2 => 'clerk',
    3 => 'luponmember',
    4 => 'summonsserver'
];

foreach ($testRoles as $roleId => $username) {
    echo "--- Testing Role {$roleId} ({$username}) ---\n";
    $user = $userModel->findByUsername($username);
    runCheck($user !== false, "User {$username} found in database");
    $userId = (int)$user['user_id'];

    // 1. Wrong current password
    echo "  [1.1] Testing incorrect current password...\n";
    $res = $settingsController->changeUserPassword($userId, 'WrongCurrentPassword!', 'NewValidPass2026!', 'NewValidPass2026!');
    runCheck($res['success'] === false, "Incorrect current password rejected ({$res['message']})");

    // 2. Mismatched confirmation
    echo "  [1.2] Testing confirmation mismatch...\n";
    $res = $settingsController->changeUserPassword($userId, 'password', 'NewValidPass2026!', 'DifferentPass2026!');
    runCheck($res['success'] === false, "Mismatched confirmation rejected ({$res['message']})");

    // 3. Short / weak password
    echo "  [1.3] Testing weak password (<12 chars)...\n";
    $res = $settingsController->changeUserPassword($userId, 'password', 'Weak1!', 'Weak1!');
    runCheck($res['success'] === false, "Short password rejected ({$res['message']})");

    // 4. Same as current password
    echo "  [1.4] Testing same password reuse...\n";
    $res = $settingsController->changeUserPassword($userId, 'password', 'password', 'password');
    runCheck($res['success'] === false, "Same password rejected ({$res['message']})");

    // 5. Valid password change
    echo "  [1.5] Executing valid password change...\n";
    $tempPass = 'TumanaRolePass' . $roleId . '2026!';
    $res = $settingsController->changeUserPassword($userId, 'password', $tempPass, $tempPass);
    runCheck($res['success'] === true, "Password updated successfully for {$username}");

    // 6. Verify authentication with new password
    echo "  [1.6] Verifying login with new password...\n";
    runCheck($auth->login($username, $tempPass) === true, "Login verified with newly updated password");

    // 7. Restore default password 'password'
    echo "  [1.7] Restoring default password for development...\n";
    $restoreRes = $settingsController->changeUserPassword($userId, $tempPass, 'PassTumana2026!', 'PassTumana2026!');
    runCheck($restoreRes['success'] === true, "Changed back to strong default");
    // Set directly back to 'password' hash for standard local seed compatibility
    $db->prepare("UPDATE users SET password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' WHERE user_id = ?")->execute([$userId]);
    runCheck($auth->login($username, 'password') === true, "Standard seed password verified");
    echo "\n";
}

echo "====================================================================\n";
echo "   ALL ROLES CHANGE PASSWORD VERIFIED SUCCESSFULLY!\n";
echo "====================================================================\n";
