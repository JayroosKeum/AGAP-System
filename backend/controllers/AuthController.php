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
            if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) session_start();
            if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);

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
        $identity = trim((string) ($data['identity'] ?? ($data['username'] ?? '')));
        if ($identity === '' || mb_strlen($identity) > 150) {
            return ['success' => false, 'message' => 'Enter your username or email address.'];
        }

        $user = $this->userModel->findActiveByUsernameOrEmail($identity);
        if (!$user) {
            return [
                'success' => true,
                'message' => 'If an active account exists for that username or email, a password reset link has been dispatched to the registered address.',
            ];
        }

        if (empty($user['email'])) {
            return [
                'success' => false,
                'message' => 'This account does not have a registered email address on file. Please contact your Lupon administrator to update your profile.',
            ];
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        if (!$this->userModel->createPasswordResetToken((int) $user['user_id'], hash('sha256', $token), $expiresAt)) {
            return ['success' => false, 'message' => 'Unable to process the reset request at this time. Please try again.'];
        }

        $this->audit->log((int) $user['user_id'], 'Requested password reset', 'Authentication', (int) $user['user_id']);

        $service = new PasswordResetService();
        $service->send($user['email'], $user['username'], $token, (string) ($user['first_name'] ?? ''));

        $maskedEmail = $this->maskEmail($user['email']);
        return [
            'success' => true,
            'message' => "A password reset link has been sent to {$maskedEmail}. It is valid for 1 hour.",
            'masked_email' => $maskedEmail,
        ];
    }

    public function isPasswordResetTokenValid(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) === 1
            && $this->userModel->hasValidPasswordResetToken(hash('sha256', $token));
    }

    public function resetPassword(array $data): array
    {
        $token = trim((string) ($data['token'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $confirm = (string) ($data['password_confirmation'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || ($error = $this->passwordError($password, $confirm)) !== null) {
            return ['success' => false, 'message' => $error ?? 'This reset link is invalid or has expired.'];
        }
        $result = $this->userModel->consumePasswordResetToken(hash('sha256', $token), $password);
        if ($result['success']) {
            $this->audit->log($result['user_id'], 'Reset password', 'Authentication', $result['user_id']);
        }
        return $result + ['message' => $result['success'] ? 'Password reset successfully. You can now sign in.' : $result['message']];
    }

    public function verifyEmail(array $data): array
    {
        $token = trim((string) ($data['token'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $confirm = (string) ($data['password_confirmation'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || ($error = $this->passwordError($password, $confirm)) !== null) {
            return ['success' => false, 'message' => $error ?? 'This verification link is invalid or has expired.'];
        }
        $result = $this->userModel->consumeEmailVerificationToken(hash('sha256', $token), $password);
        if ($result['success']) {
            $this->audit->log($result['user_id'], 'Verified staff email and activated account', 'Authentication', $result['user_id']);
        }
        return $result + ['message' => $result['success'] ? 'Email verified. Your account is now active; you can sign in.' : $result['message']];
    }

    public function isEmailVerificationTokenValid(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) === 1
            && $this->userModel->hasValidEmailVerificationToken(hash('sha256', $token));
    }

    /** Backwards-compatible endpoint for the existing activation page. */
    public function activateAccount(array $data): array
    {
        return $this->verifyEmail($data);
    }

    public function changePassword(array $data, int $userId): array
    {
        $current = (string) ($data['current_password'] ?? '');
        $password = (string) ($data['password'] ?? '');
        $confirm = (string) ($data['password_confirmation'] ?? '');
        if (!$this->userModel->verifyPassword($userId, $current)) {
            return ['success' => false, 'message' => 'Your current password is incorrect.'];
        }
        if (($error = $this->passwordError($password, $confirm)) !== null) {
            return ['success' => false, 'message' => $error];
        }
        if ($current === $password) {
            return ['success' => false, 'message' => 'Choose a password different from your current password.'];
        }
        if (!$this->userModel->updatePassword($userId, $password)) {
            return ['success' => false, 'message' => 'Unable to update the password.'];
        }
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        session_regenerate_id(true);
        $this->audit->log($userId, 'Changed password', 'Authentication', $userId);
        return ['success' => true, 'message' => 'Password changed successfully.'];
    }

    private function passwordError(string $password, string $confirm): ?string
    {
        if ($password !== $confirm) {
            return 'Password confirmation does not match.';
        }
        if (strlen($password) < 12 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Use at least 12 characters with uppercase, lowercase, and a number.';
        }
        return null;
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) return 'registered email';
        $name = $parts[0];
        $domain = $parts[1];
        $len = strlen($name);
        if ($len <= 2) {
            $masked = substr($name, 0, 1) . '*';
        } else {
            $masked = substr($name, 0, 1) . str_repeat('*', min(4, $len - 2)) . substr($name, -1);
        }
        return $masked . '@' . $domain;
    }

    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];
        session_destroy();
    }
}
