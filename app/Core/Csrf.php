<?php
declare(strict_types=1);

namespace App\Core;

class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verify(Request $request): void
    {
        if (Env::get('CSRF_ENABLED', 'true') !== 'true') {
            return;
        }
        $method = $request->method();
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $headerToken = $request->header('X-CSRF-Token');
        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if ($headerToken !== null && $sessionToken !== '' && hash_equals($sessionToken, $headerToken)) {
            return;
        }

        Response::error('Invalid or missing CSRF token. Refresh the page and try again.', 403);
    }
}
