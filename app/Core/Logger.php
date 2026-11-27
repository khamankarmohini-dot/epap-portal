<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

class Logger
{
    private const MAX_LOG_BYTES = 5242880; // 5 MB
    private const MAX_ROTATIONS = 3;

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $line = sprintf(
            "[%s] %s: %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );

        // In production / Render, always emit to stderr so platform Logs tab captures it
        if (Env::isProduction() || Env::isRender() || strtolower((string) Env::get('LOG_STDERR', '')) === 'true') {
            @file_put_contents('php://stderr', $line);
        }

        // Best-effort file log (optional on ephemeral filesystems)
        $dir = EPAP_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            $file = $dir . '/app.log';
            self::rotateIfNeeded($file);
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        }
    }

    private static function rotateIfNeeded(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        clearstatcache(true, $file);
        if ((int) @filesize($file) < self::MAX_LOG_BYTES) {
            return;
        }
        for ($i = self::MAX_ROTATIONS - 1; $i >= 1; $i--) {
            $src = "{$file}.{$i}";
            $dst = "{$file}." . ($i + 1);
            if (is_file($src)) {
                @rename($src, $dst);
            }
        }
        @rename($file, "{$file}.1");
    }

    public static function audit(PDO $pdo, ?int $userId, string $action, string $detail = '', string $status = 'SUCCESS'): void
    {
        try {
            $ip = Request::clientIp();
            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs (user_id, action, detail, ip, status) VALUES (?,?,?,?,?)'
            );
            $stmt->execute([$userId, $action, $detail, $ip, $status]);
        } catch (\Throwable $e) {
            self::error('Audit log insert failed: ' . $e->getMessage());
        }
    }
}
