<?php

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/PasswordResetService.php';
require_once __DIR__ . '/../services/EmailVerificationMailer.php';
require_once __DIR__ . '/../services/GoogleResidentIdentity.php';

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
        return $this->attemptLogin((string) $username, (string) $password);
    }

    /** Authenticate only an authorized personnel account. */
    public function loginStaff(string $username, string $password): bool
    {
        return $this->attemptLogin($username, $password, false);
    }

    /** Authenticate only a public resident account. */
    public function loginResident(string $username, string $password): bool
    {
        return $this->attemptLogin($username, $password, true);
    }

    public function registerResident(array $data): array
    {
        $googleConfigured = GoogleResidentIdentity::clientId() !== '';
        $identity = $googleConfigured ? ($_SESSION['resident_google_registration'] ?? null) : null;
        if ($googleConfigured && (!is_array($identity) || ($identity['expires_at'] ?? 0) <= time())) {
            return ['success' => false, 'code' => 'google_required', 'message' => 'Confirm your Gmail address with Google first.'];
        }

        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));
        $email = $identity['email'] ?? strtolower(trim((string) ($data['email'] ?? '')));
        $contactNo = trim((string) ($data['contact_no'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $confirmation = (string) ($data['password_confirmation'] ?? '');

        if (($data['terms_agreed'] ?? '') !== '1') {
            return ['success' => false, 'code' => 'validation', 'message' => 'You must agree to the Resident Portal User Agreement.'];
        }

        if ($firstName === '' || $lastName === '' || mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            return ['success' => false, 'code' => 'validation', 'message' => 'Enter your first and last name.'];
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
            return ['success' => false, 'code' => 'validation', 'message' => 'Choose a username with 3 to 100 letters, numbers, periods, underscores, or hyphens.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            return ['success' => false, 'code' => 'validation', 'message' => 'Enter a valid email address.'];
        }
        if ($contactNo !== '' && (!preg_match('/^[0-9+() -]{7,20}$/', $contactNo))) {
            return ['success' => false, 'code' => 'validation', 'message' => 'Enter a valid contact number.'];
        }
        if (($error = $this->passwordError($password, $confirmation)) !== null) {
            return ['success' => false, 'code' => 'validation', 'message' => $error];
        }
        if ($this->userModel->usernameInUse($username) || $this->userModel->emailInUse($email)) {
            return ['success' => false, 'code' => 'duplicate', 'message' => 'That username or email address is already registered.'];
        }

        $result = $this->userModel->createResidentAccount([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => $username,
            'email' => $email,
            'contact_no' => $contactNo,
            'password' => $password,
            'google_subject' => $identity['google_subject'] ?? null,
        ]);
        if ($result['success']) {
            unset($_SESSION['resident_google_registration']);
            $event = $googleConfigured ? 'Registered with a Google-verified Gmail address and accepted User Agreement'
                : 'Registered resident portal account and accepted User Agreement';
            $this->audit->log((int) $result['user_id'], $event, 'Resident Portal', (int) $result['resident_id']);
            $result['google_verified'] = $googleConfigured;
        }
        return $result;
    }

    /**
     * Quick / Unverified Resident registration with auto-generated RES-TMN-XXXXX handle.
     * Supports deferred identity disclosure until BPSO review or self-service profile completion.
     */
    public function registerQuickResident(array $data): array
    {
        if (($data['terms_agreed'] ?? '') !== '1') {
            return ['success' => false, 'code' => 'validation', 'message' => 'You must agree to the Resident Portal User Agreement.'];
        }

        $password = (string) ($data['password'] ?? '');
        $confirmation = (string) ($data['password_confirmation'] ?? '');
        if (($error = $this->passwordError($password, $confirmation)) !== null) {
            return ['success' => false, 'code' => 'validation', 'message' => $error];
        }

        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $contactNo = trim((string) ($data['contact_no'] ?? ''));

        if ($firstName === '') {
            $firstName = 'Unverified';
        }
        if ($lastName === '') {
            $lastName = 'Resident';
        }
        if (mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            return ['success' => false, 'code' => 'validation', 'message' => 'Name fields must be 100 characters or fewer.'];
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)) {
            return ['success' => false, 'code' => 'validation', 'message' => 'Enter a valid email address or leave it blank for now.'];
        }
        if ($email !== '' && $this->userModel->emailInUse($email)) {
            return ['success' => false, 'code' => 'duplicate', 'message' => 'That email address is already registered.'];
        }
        if ($contactNo !== '' && !preg_match('/^[0-9+() .-]{7,20}$/', $contactNo)) {
            return ['success' => false, 'code' => 'validation', 'message' => 'Enter a valid contact number or leave it blank for now.'];
        }

        $handle = $this->userModel->generateResidentHandle();
        $result = $this->userModel->createResidentAccount([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => $handle,
            'email' => $email !== '' ? $email : null,
            'contact_no' => $contactNo !== '' ? $contactNo : null,
            'password' => $password,
            'google_subject' => null,
        ]);

        if (!empty($result['success'])) {
            $this->audit->log((int) $result['user_id'], 'Registered unverified resident account (' . $handle . ')', 'Resident Portal', (int) $result['resident_id']);
            $userRow = $this->userModel->findByUsername($handle);
            if ($userRow) {
                $this->establishSession($userRow);
            }
            $result['username'] = $handle;
            $result['verification_tier'] = 'Unverified / Pending Verification';
        }
        return $result;
    }

    public function acceptResidentGoogleIdentity(array $identity): string
    {
        unset($_SESSION['resident_google_registration']);
        $account = $this->userModel->findResidentByGoogleSubject($identity['google_subject']);
        if ($account) {
            return $account['status'] === 'Active' && $this->establishSession($account) ? 'signed_in' : 'disabled';
        }
        if ($this->userModel->emailInUse($identity['email'])) {
            return 'email_in_use';
        }
        session_regenerate_id(true);
        $_SESSION['resident_google_registration'] = $identity + ['expires_at' => time() + 900];
        return 'register';
    }

    public function residentEmailPending(string $username, string $password): bool
    {
        return $this->pendingResidentAccount($username, $password) !== null;
    }

    public function resendResidentVerification(string $username, string $password): string
    {
        $account = $this->pendingResidentAccount($username, $password);
        if (!$account) {
            return 'invalid_credentials';
        }
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $id = (int) $account['user_id'];
        if (!$this->userModel->renewResidentVerificationToken($id, $account['email_verification_token'], $hash)) {
            return 'verification_wait';
        }
        if (!$this->sendResidentVerification($account['email'], $account['first_name'], $token, $id)) {
            $this->userModel->restoreResidentVerificationToken($account, $hash);
            return 'verification_failed';
        }
        return 'verification_sent';
    }

    public function confirmResidentEmail(string $token, string $password): bool
    {
        if ($password === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return false;
        }
        $tokenHash = hash('sha256', $token);
        $passwordHash = $this->userModel->pendingResidentPasswordHash($tokenHash);
        return $passwordHash !== null && password_verify($password, $passwordHash)
            && $this->userModel->confirmResidentEmail($tokenHash);
    }

    private function pendingResidentAccount(string $username, string $password): ?array
    {
        $account = $this->userModel->findByUsername($username);
        return $account && $account['role_name'] === 'Resident' && $account['status'] === 'Inactive'
            && !empty($account['email_verification_token']) && is_string($account['password_hash'])
            && password_verify($password, $account['password_hash']) ? $account : null;
    }

    private function sendResidentVerification(string $email, string $firstName, string $token, int $userId): bool
    {
        try {
            (new EmailVerificationMailer())->sendResident($email, $firstName, $token);
            return true;
        } catch (Throwable $exception) {
            error_log('AGAP resident verification email failed for user ID ' . $userId . ': ' . $exception->getMessage());
            return false;
        }
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

    public function requestPasswordResetWithGoogle(string $credential, string $nonce): array
    {
        try {
            $identity = (new GoogleResidentIdentity())->confirm($credential, $nonce);
        } catch (Throwable $exception) {
            error_log('Google password reset verification failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'message' => 'Google could not verify this Gmail sign-in. Please try signing in with your Gmail account again.',
            ];
        }

        return $this->dispatchPasswordResetForGoogleIdentity($identity);
    }

    public function dispatchPasswordResetForGoogleIdentity(array $identity): array
    {
        $googleSubject = trim((string) ($identity['google_subject'] ?? ''));
        $email = strtolower(trim((string) ($identity['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'A valid Gmail address is required.'];
        }

        $user = $this->userModel->findActiveByGoogleSubjectOrEmail($googleSubject, $email);
        if (!$user) {
            return [
                'success' => false,
                'message' => "No active AGAP account is registered with {$email}. Please sign in with the Gmail address linked to your account.",
            ];
        }

        $recipientEmail = !empty($user['email']) ? (string) $user['email'] : $email;
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        if (!$this->userModel->createPasswordResetToken((int) $user['user_id'], hash('sha256', $token), $expiresAt)) {
            return ['success' => false, 'message' => 'Unable to process the reset request at this time. Please try again.'];
        }

        $this->audit->log((int) $user['user_id'], 'Requested password reset via Google Gmail sign-in', 'Authentication', (int) $user['user_id']);

        $service = new PasswordResetService();
        if (!$service->send($recipientEmail, (string) $user['username'], $token, (string) ($user['first_name'] ?? ''))) {
            return [
                'success' => false,
                'message' => 'We verified your Gmail account, but could not send the confirmation email right now. Please try again shortly.',
            ];
        }

        $maskedEmail = $this->maskEmail($recipientEmail);
        return [
            'success' => true,
            'email' => $recipientEmail,
            'masked_email' => $maskedEmail,
            'message' => "Gmail verified ({$recipientEmail}). A password reset confirmation email has been sent to your Gmail inbox (valid for 1 hour). Open that email and click the confirmation link to enter your new password.",
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
            $result['redirect'] = (($result['role_name'] ?? '') === 'Resident') ? '../resident/login.php' : 'login.php';
        }
        return $result + ['message' => $result['success'] ? 'Password reset successfully. Redirecting you to sign in…' : $result['message']];
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

    private function attemptLogin(string $username, string $password, ?bool $residentOnly = null): bool
    {
        $user = ($residentOnly === true || str_contains($username, '@'))
            ? $this->userModel->findByUsernameOrEmail($username)
            : $this->userModel->findByUsername($username);
        $isResident = ($user['role_name'] ?? '') === 'Resident';
        if (
            !$user ||
            $user['status'] !== 'Active' ||
            !is_string($user['password_hash'] ?? null) ||
            !password_verify($password, $user['password_hash']) ||
            ($residentOnly !== null && $residentOnly !== $isResident)
        ) {
            return false;
        }

        return $this->establishSession($user);
    }

    private function establishSession(array $user): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            session_start();
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['role_id'] = $user['role_id'];
        $_SESSION['role_name'] = $user['role_name'];
        $_SESSION['username'] = $user['username'];
        return true;
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
