<?php

/** Sends account verification links through Gmail SMTP. */
final class EmailVerificationMailer
{
    public function send(string $email, string $firstName, string $username, string $role, string $token): void
    {
        $link = $this->verificationLink('/verify-email', $token);
        $this->sendMessage($email, $firstName, "Your {$role} staff account has been created. Your username is {$username}.",
            'Staff Account Verification', 'Verify email and set password', $link);
    }

    public function sendResident(string $email, string $firstName, string $token): void
    {
        $link = $this->verificationLink('/frontend/pages/resident/verify-email.php', $token);
        $this->sendMessage($email, $firstName, 'Confirm your email address to activate your AGAP Resident Portal account.',
            'Resident Account Verification', 'Confirm email address', $link);
    }

    private function sendMessage(string $email, string $firstName, string $intro, string $subject, string $action, string $link): void
    {
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('PHPMailer is not installed.');
        }
        require_once $autoload;

        $smtpUser = $this->environment('GMAIL_SMTP_USERNAME');
        $smtpPassword = $this->environment('GMAIL_SMTP_APP_PASSWORD');
        if (!filter_var($smtpUser, FILTER_VALIDATE_EMAIL) || $smtpPassword === '') {
            throw new RuntimeException('Configure the Gmail sender address and app password before sending verification emails.');
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = preg_replace('/\s+/', '', $smtpPassword);
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 15;
        $mail->CharSet = 'UTF-8';

        $caBundle = $this->environment('GMAIL_SMTP_CA_FILE');
        if ($caBundle !== '') {
            if (!is_readable($caBundle)) {
                throw new RuntimeException('The configured Gmail SMTP CA bundle cannot be read.');
            }
            $mail->SMTPOptions = ['ssl' => ['cafile' => $caBundle]];
        } elseif (!ini_get('openssl.cafile') && is_readable((string) ini_get('curl.cainfo'))) {
            // Laragon can configure a CA bundle for cURL only.
            $mail->SMTPOptions = ['ssl' => ['cafile' => ini_get('curl.cainfo')]];
        }

        $mail->setFrom($smtpUser, 'Barangay Tumana Portal');
        $mail->addAddress($email, $firstName);
        $mail->isHTML(true);
        $mail->Subject = 'Barangay Tumana Portal - ' . $subject;

        $safeFirstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
        $safeIntro = htmlspecialchars($intro, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $mail->Body = "<p>Hello {$safeFirstName},</p><p>{$safeIntro}</p><p><a href=\"{$safeLink}\">{$action}</a></p><p>This link expires in 24 hours.</p>";
        $mail->AltBody = "Hello {$firstName},\n\n{$intro}\n\n{$action}: {$link}\n\nThis link expires in 24 hours.";
        $mail->send();
    }

    private function verificationLink(string $path, string $token): string
    {
        $configured = $this->environment('AGAP_APP_URL');
        $baseUrl = is_string($configured) && filter_var($configured, FILTER_VALIDATE_URL)
            ? rtrim($configured, '/')
            : 'http://localhost/AGAP';
        return $baseUrl . $path . '?token=' . rawurlencode($token);
    }

    private function environment(string $name): string
    {
        $val = getenv($name);
        if (is_string($val) && $val !== '') return $val;
        $val = $_ENV[$name] ?? ($_SERVER[$name] ?? '');
        return is_string($val) ? trim($val) : '';
    }
}
