<?php
declare(strict_types=1);

namespace App\Core;

class Mailer
{
    private static ?string $lastError = null;

    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    public static function driverName(): string
    {
        $explicit = strtolower(trim((string) Env::get('MAIL_DRIVER', '')));
        if (in_array($explicit, ['brevo_api', 'smtp', 'log'], true)) {
            return $explicit;
        }
        if (trim((string) Env::get('BREVO_API_KEY', '')) !== '') {
            return 'brevo_api';
        }
        if (trim((string) Env::get('SMTP_HOST', '')) !== '') {
            return 'smtp';
        }
        return Env::isLocal() ? 'log' : 'smtp';
    }

    public static function fromAddress(): string
    {
        return trim((string) Env::get('MAIL_FROM_ADDRESS', Env::get('SMTP_FROM_EMAIL', '')));
    }

    public static function fromName(): string
    {
        return trim((string) Env::get('MAIL_FROM_NAME', Env::get('SMTP_FROM_NAME', 'EPAP Portal')));
    }

    public static function isConfigured(): bool
    {
        $driver = self::driverName();
        $from = self::fromAddress();
        if ($driver === 'log') {
            return Env::isLocal();
        }
        if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        if ($driver === 'brevo_api') {
            return trim((string) Env::get('BREVO_API_KEY', '')) !== '';
        }
        return trim((string) Env::get('SMTP_HOST', '')) !== '';
    }

    public static function isSmtpConfigured(): bool
    {
        return self::isConfigured();
    }

    public static function sendOtp(string $toEmail, string $fullName, string $otp, string $purpose = 'login', array $meta = []): bool
    {
        self::$lastError = null;
        $institution = (string) ($meta['institution_name'] ?? Settings::get('institution_name', 'EPAP Portal'));

        if ($purpose === 'password_reset') {
            $subject = 'Your EPAP password reset code';
            $heading = 'Password Reset Verification';
            $intro = 'We received a request to reset your EPAP account password. Enter the 6-digit verification code below to complete the reset:';
            $warning = "If you didn't request a password reset, ignore this email and contact your administrator.";
        } else {
            $subject = 'Your EPAP verification code';
            $heading = 'Sign-In Verification Code';
            $intro = 'Use the 6-digit verification code below to complete your sign-in to EPAP:';
            $warning = "If you didn't try to log in, ignore this email and contact your administrator.";
        }

        $safeName = htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeInst = htmlspecialchars($institution, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeOtp  = htmlspecialchars($otp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeWarn = htmlspecialchars($warning, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>{$subject}</title></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:8px;border:1px solid #e2e8f0;overflow:hidden;">
          <tr>
            <td style="background:#0f172a;color:#ffffff;padding:20px 24px;">
              <div style="font-size:16px;font-weight:bold;">{$safeInst}</div>
              <div style="font-size:12px;color:#cbd5e1;margin-top:4px;">{$heading}</div>
            </td>
          </tr>
          <tr>
            <td style="padding:24px;">
              <p style="margin:0 0 12px 0;font-size:14px;">Hello {$safeName},</p>
              <p style="margin:0 0 18px 0;font-size:14px;line-height:1.5;">{$intro}</p>
              <div style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:6px;padding:16px;text-align:center;margin:0 0 18px 0;">
                <span style="font-size:28px;font-weight:bold;letter-spacing:6px;font-family:monospace;color:#0f172a;">{$safeOtp}</span>
              </div>
              <p style="margin:0 0 12px 0;font-size:13px;color:#475569;">
                This code expires in <strong>5 minutes</strong> and can only be used once. Never share it with anyone.
              </p>
              <p style="margin:16px 0 0 0;padding:12px;background:#fff7ed;border-left:4px solid #f59e0b;font-size:12px;color:#92400e;">
                {$safeWarn}
              </p>
            </td>
          </tr>
          <tr>
            <td style="background:#f8fafc;padding:14px 24px;font-size:11px;color:#64748b;border-top:1px solid #e2e8f0;">
              Automated security message from {$safeInst}. Do not reply to this email.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

        $textBody = "Hello {$fullName},\n\n"
            . "{$intro}\n\n"
            . "Verification Code: {$otp}\n\n"
            . "This code expires in 5 minutes and can only be used once. Never share it with anyone.\n\n"
            . "{$warning}\n\n"
            . "{$institution}\n";

        if (self::driverName() === 'log') {
            return self::sendViaLog($toEmail, $fullName, $subject, $otp, $purpose);
        }

        return self::dispatch($toEmail, $fullName, $subject, $htmlBody, $textBody);
    }

    public static function sendAccountReady(string $toEmail, string $fullName, string $identifier, ?string $tempPassword = null): bool
    {
        self::$lastError = null;
        $institution = Settings::get('institution_name', 'EPAP Portal');
        $subject = 'Your EPAP account is ready';

        $safeName = htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeInst = htmlspecialchars($institution, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeId   = htmlspecialchars($identifier, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($tempPassword !== null && $tempPassword !== '') {
            $safePw = htmlspecialchars($tempPassword, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $pwHtml = "<p style=\"margin:8px 0;font-size:14px;\"><strong>Temporary Password:</strong> <code>{$safePw}</code></p>";
            $pwText = "Temporary Password: {$tempPassword}\n";
        } else {
            $pwHtml = '<p style="margin:8px 0;font-size:13px;color:#475569;">Obtain your temporary password from your department administrator.</p>';
            $pwText = "Obtain your temporary password from your department administrator.\n";
        }

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>{$subject}</title></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:8px;border:1px solid #e2e8f0;overflow:hidden;">
        <tr><td style="background:#0f172a;color:#ffffff;padding:20px 24px;">
          <div style="font-size:16px;font-weight:bold;">{$safeInst}</div>
          <div style="font-size:12px;color:#cbd5e1;margin-top:4px;">Account Provisioned</div>
        </td></tr>
        <tr><td style="padding:24px;">
          <p style="margin:0 0 12px 0;font-size:14px;">Hello {$safeName},</p>
          <p style="margin:0 0 12px 0;font-size:14px;">Your EPAP portal account has been created.</p>
          <p style="margin:8px 0;font-size:14px;"><strong>Login ID:</strong> <code>{$safeId}</code></p>
          {$pwHtml}
          <p style="margin:12px 0 0 0;font-size:13px;color:#475569;">You will be required to change your password on first sign-in.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

        $textBody = "Hello {$fullName},\n\nYour EPAP portal account has been created.\nLogin ID: {$identifier}\n{$pwText}\nYou will be required to change your password on first sign-in.\n\n{$institution}\n";

        if (self::driverName() === 'log') {
            return true;
        }
        return self::dispatch($toEmail, $fullName, $subject, $htmlBody, $textBody);
    }

    public static function sendTestEmail(string $toEmail): bool
    {
        self::$lastError = null;
        $institution = Settings::get('institution_name', 'EPAP Portal');
        $driver = self::driverName();
        $subject = 'EPAP Email Test (' . $driver . ')';
        $html = "<p>This is a test email from <strong>" . htmlspecialchars($institution, ENT_QUOTES, 'UTF-8') . "</strong> confirming <code>" . htmlspecialchars($driver, ENT_QUOTES, 'UTF-8') . "</code> delivery is working.</p>";
        $text = "This is a test email from {$institution} confirming {$driver} delivery is working.\n";
        if ($driver === 'log') {
            return self::sendViaLog($toEmail, $toEmail, $subject, '000000', 'test');
        }
        return self::dispatch($toEmail, $toEmail, $subject, $html, $text);
    }

    private static function dispatch(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): bool
    {
        $driver = self::driverName();
        if ($driver === 'brevo_api') {
            return self::sendViaBrevoApi($toEmail, $toName, $subject, $htmlBody, $textBody);
        }
        if ($driver === 'log') {
            return self::sendViaLog($toEmail, $toName, $subject, '—', 'general');
        }
        return self::sendViaSmtp($toEmail, $toName, $subject, $htmlBody, $textBody);
    }

    private static function sendViaLog(string $toEmail, string $fullName, string $subject, string $otp, string $purpose): bool
    {
        $mailDir = EPAP_ROOT . '/storage/mail';
        if (!is_dir($mailDir)) {
            @mkdir($mailDir, 0775, true);
        }
        $logLine = sprintf(
            "[%s] TO: %s (%s) | PURPOSE: %s | SUBJECT: %s | OTP: %s\n",
            date('Y-m-d H:i:s'),
            $toEmail,
            $fullName,
            $purpose,
            $subject,
            $otp
        );
        @file_put_contents($mailDir . '/otp.log', $logLine, FILE_APPEND | LOCK_EX);
        return true;
    }

    private static function sendViaBrevoApi(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): bool
    {
        if (!function_exists('curl_init')) {
            self::$lastError = 'PHP cURL extension is required for MAIL_DRIVER=brevo_api.';
            Logger::error(self::$lastError);
            return false;
        }

        $apiKey = trim((string) Env::get('BREVO_API_KEY', ''));
        $fromEmail = self::fromAddress();
        $fromName = self::fromName();

        if ($apiKey === '') {
            self::$lastError = 'BREVO_API_KEY is not configured.';
            Logger::error('Brevo API configuration error: ' . self::$lastError);
            return false;
        }
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            self::$lastError = 'MAIL_FROM_ADDRESS (or SMTP_FROM_EMAIL) is missing or invalid.';
            Logger::error('Brevo API configuration error: ' . self::$lastError);
            return false;
        }

        $endpoint = trim((string) Env::get('BREVO_API_URL', 'https://api.brevo.com/v3/smtp/email'));
        $payload = json_encode([
            'sender' => [
                'name' => $fromName !== '' ? $fromName : 'EPAP Portal',
                'email' => $fromEmail,
            ],
            'to' => [[
                'email' => $toEmail,
                'name' => $toName !== '' ? $toName : $toEmail,
            ]],
            'subject' => $subject,
            'htmlContent' => $htmlBody,
            'textContent' => $textBody,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $resp = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $curlErr !== '') {
            $err = self::sanitizeError('HTTPS request failed: ' . $curlErr, $apiKey);
            self::$lastError = $err;
            Logger::error('Brevo API delivery failed: ' . $err);
            return false;
        }

        if ($status >= 200 && $status < 300) {
            return true;
        }

        $decoded = json_decode((string) $resp, true);
        $providerMsg = is_array($decoded)
            ? (string) ($decoded['message'] ?? $decoded['code'] ?? $resp)
            : (string) $resp;
        $err = self::sanitizeError("Brevo API HTTP {$status}: " . substr($providerMsg, 0, 200), $apiKey);
        self::$lastError = $err;
        Logger::error('Brevo API delivery failed: ' . $err, ['status' => $status]);
        return false;
    }

    private static function sendViaSmtp(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): bool
    {
        $autoload = EPAP_ROOT . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            self::$lastError = 'PHPMailer is not installed (vendor/autoload.php missing). Run: composer install';
            Logger::error(self::$lastError);
            return false;
        }
        require_once $autoload;
        if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            self::$lastError = 'PHPMailer class not found. Run: composer install';
            Logger::error(self::$lastError);
            return false;
        }

        $smtpHost = trim((string) Env::get('SMTP_HOST', ''));
        $smtpPort = (int) Env::get('SMTP_PORT', '587');
        $smtpSecure = strtolower(trim((string) Env::get('SMTP_SECURE', Env::get('SMTP_ENCRYPTION', 'tls'))));
        $smtpUser = (string) Env::get('SMTP_USER', '');
        $smtpPass = (string) Env::get('SMTP_PASS', '');
        $fromEmail = self::fromAddress();
        $fromName = self::fromName();

        if ($smtpHost === '') {
            self::$lastError = 'SMTP_HOST is not configured.';
            Logger::error('SMTP configuration error: ' . self::$lastError);
            return false;
        }
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            self::$lastError = 'MAIL_FROM_ADDRESS / SMTP_FROM_EMAIL is missing or invalid.';
            Logger::error('SMTP configuration error: ' . self::$lastError);
            return false;
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->Port = $smtpPort;
            $mail->Timeout = 10;
            $mail->CharSet = 'UTF-8';

            if ($smtpUser !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $smtpUser;
                $mail->Password = $smtpPass;
            } else {
                $mail->SMTPAuth = false;
            }

            if ($smtpSecure === 'ssl' || $smtpSecure === 'smtps' || $smtpPort === 465) {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($smtpSecure === 'none' && in_array($smtpHost, ['127.0.0.1', 'localhost'], true)) {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            } else {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }

            if ($mail->SMTPSecure !== '') {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'allow_self_signed' => false,
                    ],
                ];
            }

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            $err = self::sanitizeError($e->getMessage(), $smtpPass);
            self::$lastError = $err;
            Logger::error('SMTP delivery failed: ' . $err, ['host' => $smtpHost, 'port' => $smtpPort]);
            return false;
        }
    }

    private static function sanitizeError(string $message, string $secret): string
    {
        if ($secret !== '') {
            $message = str_replace($secret, '[REDACTED]', $message);
        }
        return $message;
    }
}
