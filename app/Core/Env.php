<?php
declare(strict_types=1);

namespace App\Core;

class Env
{
    private static array $vars = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            if ((str_starts_with($val, '"') && str_ends_with($val, '"'))
                || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            self::$vars[$key] = $val;
            if (!isset($_ENV[$key]) && getenv($key) === false) {
                $_ENV[$key] = $val;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $envVal = getenv($key);
        if ($envVal !== false) {
            return (string) $envVal;
        }
        if (array_key_exists($key, $_ENV)) {
            return (string) $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER) && is_scalar($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key];
        }
        return $default;
    }

    public static function isLocal(): bool
    {
        return strtolower((string) self::get('APP_ENV', 'local')) === 'local';
    }

    public static function isProduction(): bool
    {
        return strtolower((string) self::get('APP_ENV', 'local')) === 'production';
    }

    public static function isRender(): bool
    {
        $r = strtolower((string) self::get('RENDER', ''));
        return $r === 'true' || $r === '1' || self::get('RENDER_SERVICE_ID', '') !== '';
    }
}
