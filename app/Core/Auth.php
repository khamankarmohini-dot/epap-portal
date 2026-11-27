<?php
declare(strict_types=1);

namespace App\Core;

class Auth
{
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null;
    }

    public static function role(): ?string
    {
        return $_SESSION['user']['role'] ?? null;
    }

    public static function requireAuth(): array
    {
        $user = self::user();
        if (!$user) {
            Response::error('Not authenticated.', 401);
        }
        return $user;
    }

    public static function requireRole(string|array $roles): array
    {
        $user = self::requireAuth();
        $allowed = is_array($roles) ? $roles : [$roles];
        if (!in_array($user['role'], $allowed, true)) {
            Response::error('Forbidden: insufficient permissions.', 403);
        }
        return $user;
    }
}
