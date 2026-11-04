<?php

/** Sends staff-account verification links through Gmail SMTP. */
final class StaffVerificationMailer
{
    public function send(string $email, string $firstName, string $username, string $role, string $token): void
    {
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('PHPMailer is not installed.');
        }
        require_once $autoload;

        $smtpUser = $this->environment('GMAIL_SMTP_USERNAME');
        $smtpPassword = $this->environment('GMAIL_SMTP_APP_PASSWORD');
        if ($smtpUser === '' || $smtpPassword === '') {
            $link = $this->verificationLink($token);
            $this->logMessage("Gmail SMTP not configured. Account verification link for {$username} ({$email}): {$link}");
            return;
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 15;
        $mail->CharSet = 'UTF-8';

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => true,
            ],
        ];

        $mail->setFrom($smtpUser, 'Barangay Tumana Portal');
        $mail->addAddress($email, $firstName);
        $mail->isHTML(true);
        $mail->Subject = 'Barangay Tumana Portal - Staff Account Verification';

        $link = $this->verificationLink($token);
        $safeFirstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
        $safeUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $safeRole = htmlspecialchars($role, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $mail->Body = "<p>Hello {$safeFirstName},</p><p>Your {$safeRole} staff account has been created. "
            . "Your username is <strong>{$safeUsername}</strong>.</p><p>Please click the secure link below to verify your email and set your password (valid for 24 hours):</p>"
            . "<p><a href=\"{$safeLink}\">Verify email and set password</a></p>";
        $mail->AltBody = "Hello {$firstName},\n\nYour {$role} staff account has been created. Your username is {$username}.\n\n"
            . "Verify your email and set your password within 24 hours:\n{$link}";
        $mail->send();
        $this->logMessage("Verification email sent via Gmail SMTP to {$email} for user {$username}.");
    }

    private function verificationLink(string $token): string
    {
        $configured = $this->environment('AGAP_APP_URL');
        $baseUrl = is_string($configured) && filter_var($configured, FILTER_VALIDATE_URL)
            ? rtrim($configured, '/')
            : 'http://localhost/AGAP';
        return $baseUrl . '/verify-email?token=' . rawurlencode($token);
    }

    private function environment(string $name): string
    {
        $val = getenv($name);
        if (is_string($val) && $val !== '') return $val;
        $val = $_ENV[$name] ?? ($_SERVER[$name] ?? '');
        return is_string($val) ? trim($val) : '';
    }

    private function logMessage(string $msg): void
    {
        $logDir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
        @file_put_contents($logDir . '/mail.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
    }
}
