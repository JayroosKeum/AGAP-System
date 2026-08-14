<?php

class PasswordResetService
{
    public function send(string $email, string $username, string $token): bool
    {
        $baseUrl = $this->baseUrl();
        $link = $baseUrl . '/frontend/pages/auth/reset-password.php?token=' . rawurlencode($token);
        $subject = 'AGAP password reset';
        $body = "Hello {$username},\n\nUse this one-time link to reset your AGAP password. It expires in one hour:\n{$link}\n\nIf you did not request this, you can ignore this message.";
        return mail($email, $subject, $body, "Content-Type: text/plain; charset=UTF-8\r\n");
    }

    private function baseUrl(): string
    {
        $configured = getenv('AGAP_APP_URL');
        if (is_string($configured) && filter_var($configured, FILTER_VALIDATE_URL)) return rtrim($configured, '/');
        return 'http://localhost/AGAP';
    }
}
