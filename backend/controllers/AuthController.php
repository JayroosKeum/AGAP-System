<?php

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/PasswordResetService.php';

class AuthController {

    private $userModel;
    private AuditService $audit;

    public function __construct()
    {
        $this->userModel = new User();
        $this->audit = new AuditService();
    }

    public function login(
        $username,
        $password
    )
    {
        $user = $this->userModel
            ->findByUsername($username);

        if(
            $user &&
            $user['status'] === 'Active' &&
            password_verify(
                $password,
                $user['password_hash']
            )
        )
        {
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            session_regenerate_id(true);

            $_SESSION['user_id']
                = $user['user_id'];

            $_SESSION['role_id']
                = $user['role_id'];

            $_SESSION['username']
                = $user['username'];

            return true;
        }

        return false;
    }

    public function requestPasswordReset(array $data): array
    {
        $identity = trim((string) ($data['identity'] ?? ''));
        if ($identity === '' || mb_strlen($identity) > 150) return ['success' => false, 'message' => 'Enter a valid username or email address.'];
        $user = $this->userModel->findActiveByUsernameOrEmail($identity);
        if (!$user || empty($user['email'])) return ['success' => true, 'message' => 'If the account is eligible, a reset link will be sent shortly.'];
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        if (!$this->userModel->createPasswordResetToken((int) $user['user_id'], hash('sha256', $token), $expiresAt)) return ['success' => false, 'message' => 'Unable to process the reset request.'];
        $this->audit->log((int) $user['user_id'], 'Requested password reset', 'Authentication', (int) $user['user_id']);
        if (!(new PasswordResetService())->send($user['email'], $user['username'], $token)) {
            error_log('AGAP password reset email delivery failed for user ID ' . $user['user_id']);
            return ['success' => true, 'message' => 'If the account is eligible, a reset link will be sent shortly.'];
        }
        return ['success' => true, 'message' => 'If the account is eligible, a reset link will be sent shortly.'];
    }

    public function resetPassword(array $data): array
    {
        $token = trim((string) ($data['token'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $confirm = (string) ($data['password_confirmation'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || ($error = $this->passwordError($password, $confirm)) !== null) return ['success' => false, 'message' => $error ?? 'This reset link is invalid or has expired.'];
        $result = $this->userModel->consumePasswordResetToken(hash('sha256', $token), $password);
        if ($result['success']) $this->audit->log($result['user_id'], 'Reset password', 'Authentication', $result['user_id']);
        return $result + ['message' => $result['success'] ? 'Password reset successfully. You can now sign in.' : $result['message']];
    }

    public function changePassword(array $data, int $userId): array
    {
        $current = (string) ($data['current_password'] ?? '');
        $password = (string) ($data['password'] ?? ''); $confirm = (string) ($data['password_confirmation'] ?? '');
        if (!$this->userModel->verifyPassword($userId, $current)) return ['success' => false, 'message' => 'Your current password is incorrect.'];
        if (($error = $this->passwordError($password, $confirm)) !== null) return ['success' => false, 'message' => $error];
        if ($current === $password) return ['success' => false, 'message' => 'Choose a password different from your current password.'];
        if (!$this->userModel->updatePassword($userId, $password)) return ['success' => false, 'message' => 'Unable to update the password.'];
        if (session_status() !== PHP_SESSION_ACTIVE) session_start(); session_regenerate_id(true);
        $this->audit->log($userId, 'Changed password', 'Authentication', $userId);
        return ['success' => true, 'message' => 'Password changed successfully.'];
    }

    private function passwordError(string $password, string $confirm): ?string
    {
        if ($password !== $confirm) return 'Password confirmation does not match.';
        if (strlen($password) < 12 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password)) return 'Use at least 12 characters with uppercase, lowercase, and a number.';
        return null;
    }

    public function logout()
    {
        session_start();

        session_unset();

        session_destroy();

        header(
            "Location: /frontend/pages/auth/login.php"
        );
    }
}
