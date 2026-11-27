<?php
declare(strict_types=1);

namespace App\Core;

class EmailChannel implements OtpChannel
{
    private ?string $lastError = null;

    public function send(string $recipient, string $fullName, string $otp, string $purpose = 'login', array $meta = []): bool
    {
        $this->lastError = null;
        $ok = Mailer::sendOtp($recipient, $fullName, $otp, $purpose, $meta);
        if (!$ok) {
            $this->lastError = Mailer::getLastError();
        }
        return $ok;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }
}
