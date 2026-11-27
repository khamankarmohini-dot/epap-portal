<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

class RateLimiter
{
    public static function checkLoginThrottle(PDO $pdo, string $identifier, string $ip, int $maxFailures = 5, int $windowMinutes = 15): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE (identifier = ? OR ip = ?) AND success = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $stmt->execute([$identifier, $ip, $windowMinutes]);
        $failures = (int) $stmt->fetchColumn();
        if ($failures >= $maxFailures) {
            Response::error('Too many failed login attempts. Please wait 15 minutes before trying again.', 429);
        }
    }

    public static function recordLoginAttempt(PDO $pdo, string $identifier, string $ip, bool $success): void
    {
        $stmt = $pdo->prepare('INSERT INTO login_attempts (identifier, ip, success) VALUES (?,?,?)');
        $stmt->execute([$identifier, $ip, $success ? 1 : 0]);
    }

    /**
     * Persistent DB-backed rate limiter across requests (cannot be bypassed by rotating session cookies).
     */
    public static function checkDbRate(PDO $pdo, string $bucket, string $key, int $maxHits, int $windowSeconds, ?string $errorMessage = null): void
    {
        // Opportunistic cleanup of expired entries (older than 1 day)
        if (random_int(1, 50) === 1) {
            $pdo->exec('DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM rate_limits
             WHERE bucket = ? AND rate_key = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $stmt->execute([$bucket, $key, $windowSeconds]);
        $hits = (int) $stmt->fetchColumn();

        if ($hits >= $maxHits) {
            Response::error($errorMessage ?? 'Rate limit exceeded. Please wait a moment and try again.', 429);
        }

        $ins = $pdo->prepare('INSERT INTO rate_limits (bucket, rate_key) VALUES (?, ?)');
        $ins->execute([$bucket, $key]);
    }

    public static function checkSessionRate(string $bucket, int $maxHits, int $windowSeconds): void
    {
        try {
            $pdo = Database::getConnection();
            $key = Request::clientIp() . '|' . (string) (Auth::userId() ?? 'anon');
            self::checkDbRate($pdo, $bucket, $key, $maxHits, $windowSeconds);
        } catch (\Throwable $e) {
            // Fallback to session if DB unavailable
            $now = time();
            $key = 'rate_' . $bucket;
            $hits = $_SESSION[$key] ?? [];
            $hits = array_values(array_filter($hits, fn($ts) => ($now - (int) $ts) < $windowSeconds));
            if (count($hits) >= $maxHits) {
                Response::error('Rate limit exceeded. Please wait a moment and try again.', 429);
            }
            $hits[] = $now;
            $_SESSION[$key] = $hits;
        }
    }
}
