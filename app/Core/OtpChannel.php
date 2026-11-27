<?php
declare(strict_types=1);

namespace App\Core;

interface OtpChannel
{
    /**
     * Deliver an OTP to the recipient.
     *
     * @param string $recipient Email address or channel-specific identifier
     * @param string $fullName  Recipient display name
     * @param string $otp       6-digit plaintext OTP
     * @param string $purpose   'login' | 'password_reset'
     * @param array  $meta      Optional metadata (e.g. institutionName)
     */
    public function send(string $recipient, string $fullName, string $otp, string $purpose = 'login', array $meta = []): bool;

    public function getLastError(): ?string;
}
