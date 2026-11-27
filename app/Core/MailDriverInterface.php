<?php
declare(strict_types=1);

namespace App\Core;

interface MailDriverInterface
{
    public function name(): string;

    public function isConfigured(): bool;

    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): bool;

    public function getLastError(): ?string;
}
