<?php

final class PasswordResetService
{
    private string $lastError = '';

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function send(string $email, string $username, string $token, string $firstName = ''): bool
    {
        $baseUrl = $this->baseUrl();
        $link = $baseUrl . '/frontend/pages/auth/reset-password.php?token=' . rawurlencode($token);
        $displayName = trim($firstName) !== '' ? $firstName : $username;

        $smtpUser = $this->environment('GMAIL_SMTP_USERNAME');
        $smtpPassword = $this->environment('GMAIL_SMTP_APP_PASSWORD');
        $hasSmtpConfig = !empty($smtpUser) && !empty($smtpPassword);

        // Always log reset link for auditing and recovery
        $this->logResetAttempt($email, $username, $link, $hasSmtpConfig);

        if (!$hasSmtpConfig) {
            // No Gmail SMTP configured yet in .env; record as simulated delivery in development
            $this->lastError = 'Gmail SMTP credentials (GMAIL_SMTP_USERNAME / GMAIL_SMTP_APP_PASSWORD) are not configured in .env.';
            error_log('[AGAP Auth] ' . $this->lastError . " Reset link: {$link}");
            // Return true in development so user workflow proceeds and link is available in log
            return true;
        }

        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (!is_file($autoload)) {
            $this->lastError = 'Autoloader not found at ' . $autoload;
            error_log('[AGAP Auth] ' . $this->lastError);
            return false;
        }
        require_once $autoload;

        try {
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

            // SSL options for local Windows environments
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => true,
                ],
            ];

            $mail->setFrom($smtpUser, 'Barangay Tumana AGAP Portal');
            $mail->addAddress($email, $displayName);
            $mail->isHTML(true);
            $mail->Subject = 'Barangay Tumana AGAP - Password Reset Request';

            $safeName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
            $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
            $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

            $mail->Body = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f5f8f5; color: #173638; margin: 0; padding: 20px; }
  .card { max-width: 560px; margin: 0 auto; background: #ffffff; border: 1px solid #d8e2db; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 14px rgba(0,0,0,0.06); }
  .header { background: #102b2d; color: #ffffff; padding: 24px; text-align: center; }
  .header h1 { margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -0.5px; }
  .header p { margin: 4px 0 0; font-size: 13px; color: #a3c4b8; }
  .body { padding: 30px 24px; line-height: 1.6; font-size: 14px; }
  .greeting { font-size: 16px; font-weight: 600; margin-bottom: 12px; }
  .btn-wrap { text-align: center; margin: 26px 0; }
  .btn { display: inline-block; background: #2b6b5e; color: #ffffff !important; padding: 12px 28px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 14px; }
  .meta-box { background: #f0f6f2; border: 1px solid #d5e5db; border-radius: 8px; padding: 12px 16px; margin: 20px 0; font-size: 12.5px; color: #244b42; }
  .footer { border-top: 1px solid #e5ede7; padding: 16px 24px; font-size: 11.5px; color: #687a76; text-align: center; }
  .link-fallback { word-break: break-all; font-size: 12px; color: #2b6b5e; }
</style>
</head>
<body>
  <div class="card">
    <div class="header">
      <h1>Barangay Tumana · AGAP</h1>
      <p>Automated Grievance Assistance Platform</p>
    </div>
    <div class="body">
      <div class="greeting">Hello, {$safeName}</div>
      <p>We received a password reset request for your AGAP staff account (<strong>{$safeUser}</strong>).</p>
      <p>To choose a new password, click the button below. This link is single-use and will expire in <strong>1 hour</strong>.</p>
      
      <div class="btn-wrap">
        <a href="{$safeLink}" class="btn" target="_blank" rel="noopener">Reset Password</a>
      </div>

      <div class="meta-box">
        <strong>Security Notice:</strong> If you did not request this password reset, no action is needed; your account remains secure. You may report unauthorized attempts to your Lupon Administrator.
      </div>

      <p style="margin-top: 20px; font-size: 12px; color: #555;">
        Button not working? Copy and paste this URL into your browser:<br>
        <a href="{$safeLink}" class="link-fallback">{$safeLink}</a>
      </p>
    </div>
    <div class="footer">
      Barangay Tumana · Lupong Tagapamayapa · Marikina City<br>
      Automated Grievance Assistance Platform (AGAP)
    </div>
  </div>
</body>
</html>
HTML;

            $mail->AltBody = "Hello {$displayName},\n\n"
                . "We received a request to reset the password for your AGAP staff account ({$username}).\n\n"
                . "Use the following link to reset your password (valid for 1 hour):\n"
                . "{$link}\n\n"
                . "If you did not request this reset, please ignore this email.\n\n"
                . "Barangay Tumana · AGAP Portal";

            $mail->send();
            $this->logMessage("Password reset email sent successfully via Gmail SMTP to {$email} for user {$username}.");
            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->logMessage("Gmail SMTP delivery failed for {$email} ({$username}): " . $this->lastError);
            error_log('[AGAP Auth Error] ' . $this->lastError);
            return false;
        }
    }

    private function logResetAttempt(string $email, string $username, string $link, bool $smtpConfigured): void
    {
        $status = $smtpConfigured ? 'SENDING_VIA_GMAIL' : 'SAVED_NO_SMTP_CONFIG';
        $logEntry = sprintf(
            "[%s] [%s] Username: %s | Email: %s | Link: %s\n",
            date('Y-m-d H:i:s'),
            $status,
            $username,
            $email,
            $link
        );

        $this->logMessage($logEntry);
    }

    private function logMessage(string $message): void
    {
        $logDir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        @file_put_contents($logDir . '/mail.log', rtrim($message) . "\n", FILE_APPEND);
    }

    private function baseUrl(): string
    {
        $configured = $this->environment('AGAP_APP_URL');
        if (!filter_var($configured, FILTER_VALIDATE_URL)) {
            $configured = $this->environment('APP_URL');
        }
        if (is_string($configured) && filter_var($configured, FILTER_VALIDATE_URL)) {
            return rtrim($configured, '/');
        }

        // Auto-detect host and scheme from request if running under web server
        if (!empty($_SERVER['HTTP_HOST'])) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
            $scheme = $isHttps ? 'https' : 'http';

            $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
            if (preg_match('#^(.*?)/(?:frontend|backend)/#', $scriptName, $matches)) {
                $basePath = rtrim($matches[1], '/');
            } else {
                $basePath = (str_starts_with($scriptName, '/AGAP/') || str_starts_with($scriptName, '/AGAP')) ? '/AGAP' : '';
            }
            return $scheme . '://' . $_SERVER['HTTP_HOST'] . $basePath;
        }

        return 'http://localhost/AGAP';
    }

    private static bool $envLoaded = false;
    private static array $envValues = [];

    private function loadEnv(): void
    {
        if (self::$envLoaded) return;
        self::$envLoaded = true;

        $envFile = dirname(__DIR__, 2) . '/.env';
        if (!file_exists($envFile)) return;

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v, " \t\n\r\0\x0B\"'");
                if ($k !== '') {
                    self::$envValues[$k] = $v;
                    if (getenv($k) === false) {
                        putenv("$k=$v");
                    }
                    if (!isset($_ENV[$k])) {
                        $_ENV[$k] = $v;
                    }
                    if (!isset($_SERVER[$k])) {
                        $_SERVER[$k] = $v;
                    }
                }
            }
        }
    }

    private function environment(string $name): string
    {
        $this->loadEnv();
        if (array_key_exists($name, self::$envValues)) {
            return self::$envValues[$name];
        }
        $val = getenv($name);
        if (is_string($val) && $val !== '') {
            return $val;
        }
        $val = $_ENV[$name] ?? ($_SERVER[$name] ?? '');
        return is_string($val) ? trim($val) : '';
    }
}
