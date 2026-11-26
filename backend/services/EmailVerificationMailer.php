<?php

/** Sends account verification links through Gmail SMTP. */
final class EmailVerificationMailer
{
    public function send(string $email, string $firstName, string $username, string $role, string $token): void
    {
        $link = $this->verificationLink('/verify-email.php', $token);
        $this->sendMessage($email, $firstName, "Your {$role} staff account has been created. Your username is {$username}.",
            'Staff Account Verification', 'Verify email and set password', $link);
    }

    public function sendResident(string $email, string $firstName, string $token): void
    {
        $link = $this->verificationLink('/frontend/pages/resident/verify-email.php', $token);
        $this->sendMessage($email, $firstName, 'Confirm your email address to activate your AGAP Resident Portal account.',
            'Resident Account Verification', 'Confirm email address', $link);
    }

    /**
     * Out-of-band A10 closure verification OTP delivery to complainant email.
     */
    public function sendClosureVerification(
        string $email,
        string $firstName,
        string $referenceLabel,
        string $resolutionType,
        string $rawOtp,
        string $expiresAt
    ): void {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $safeName = htmlspecialchars($firstName ?: 'Complainant', ENT_QUOTES, 'UTF-8');
        $safeRef = htmlspecialchars($referenceLabel, ENT_QUOTES, 'UTF-8');
        $safeType = htmlspecialchars($resolutionType, ENT_QUOTES, 'UTF-8');
        $safeOtp = htmlspecialchars($rawOtp, ENT_QUOTES, 'UTF-8');
        $safeExp = htmlspecialchars($expiresAt, ENT_QUOTES, 'UTF-8');

        $html = "<p>Hello {$safeName},</p>"
            . "<p>Barangay Tumana staff requested <strong>Pending Close ({$safeType})</strong> for record <strong>{$safeRef}</strong>.</p>"
            . "<p>Your one-time verification OTP is: <strong>{$safeOtp}</strong> (valid until {$safeExp}).</p>"
            . "<p>Log in to the AGAP Resident Portal or present this OTP only if you confirm or wish to object to final closure.</p>";
        $text = "Hello " . ($firstName ?: 'Complainant') . ",\n\n"
            . "Barangay Tumana staff requested Pending Close ({$resolutionType}) for {$referenceLabel}.\n"
            . "Verification OTP: {$rawOtp} (expires {$expiresAt}).\n"
            . "Confirm or submit an objection in the AGAP Resident Portal before expiry.";

        $this->dispatchMail($email, $firstName ?: 'Complainant', "Pending Close Verification ({$referenceLabel})", $html, $text);
    }

    private function sendMessage(string $email, string $firstName, string $intro, string $subject, string $action, string $link): void
    {
        $safeFirstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
        $safeIntro = htmlspecialchars($intro, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $html = "<p>Hello {$safeFirstName},</p><p>{$safeIntro}</p><p><a href=\"{$safeLink}\">{$action}</a></p><p>This link expires in 24 hours.</p>";
        $text = "Hello {$firstName},\n\n{$intro}\n\n{$action}: {$link}\n\nThis link expires in 24 hours.";
        $this->dispatchMail($email, $firstName, $subject, $html, $text);
    }

    private function dispatchMail(string $email, string $firstName, string $subject, string $htmlBody, string $altBody): void
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
        $mail->Body = $htmlBody;
        $mail->AltBody = $altBody;
        $mail->send();
    }

    private function verificationLink(string $path, string $token): string
    {
        $configured = $this->environment('AGAP_APP_URL');
        if (is_string($configured) && filter_var($configured, FILTER_VALIDATE_URL)) {
            $baseUrl = rtrim($configured, '/');
        } elseif (!empty($_SERVER['HTTP_HOST'])) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
            $scheme = $isHttps ? 'https' : 'http';
            $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
            if (preg_match('#^(.*?)/(?:frontend|backend)/#', $scriptName, $matches)) {
                $basePath = rtrim($matches[1], '/');
            } else {
                $basePath = (str_starts_with($scriptName, '/AGAP/') || $scriptName === '/AGAP') ? '/AGAP' : '';
            }
            $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $basePath;
        } else {
            $baseUrl = 'http://localhost/AGAP';
        }
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
