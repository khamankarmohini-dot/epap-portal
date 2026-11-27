<?php
declare(strict_types=1);

namespace App\Core;

class Request
{
    private ?array $jsonBody = null;

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        if (str_starts_with($uri, '/api')) {
            $uri = substr($uri, 4) ?: '/';
        }
        return '/' . ltrim($uri, '/');
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public function body(): array
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ct, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw ?: '{}', true);
            $this->jsonBody = is_array($decoded) ? $decoded : [];
        } else {
            $this->jsonBody = $_POST;
        }
        return $this->jsonBody;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $data = $this->body();
        return $data[$key] ?? $default;
    }

    public function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    public static function isTrustedProxy(?string $remoteAddr = null): bool
    {
        $ip = $remoteAddr ?? ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            return false;
        }
        $raw = trim((string) Env::get('TRUSTED_PROXIES', ''));
        if ($raw === '') {
            return false;
        }
        foreach (array_map('trim', explode(',', $raw)) as $rule) {
            if ($rule === '') {
                continue;
            }
            if ($rule === '*' || $rule === $ip) {
                return true;
            }
            if (str_contains($rule, '/') && self::ipInCidr($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

    public static function isHttpsRequest(): bool
    {
        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443')) {
            return true;
        }
        if (self::isTrustedProxy()) {
            $xfp = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
            if ($xfp === 'https') {
                return true;
            }
        }
        return false;
    }

    public static function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (self::isTrustedProxy($remote) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
            foreach ($parts as $cand) {
                if (filter_var($cand, FILTER_VALIDATE_IP)) {
                    return $cand;
                }
            }
        }
        return $remote;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipBin = @inet_pton($ip);
        $subBin = @inet_pton($subnet);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
            return false;
        }
        $maskBits = (int) $bits;
        $maxBits = strlen($ipBin) * 8;
        if ($maskBits < 0 || $maskBits > $maxBits) {
            return false;
        }
        $bytes = intdiv($maskBits, 8);
        $rem = $maskBits % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
            return false;
        }
        if ($rem > 0) {
            $mask = (0xFF << (8 - $rem)) & 0xFF;
            if ((ord($ipBin[$bytes]) & $mask) !== (ord($subBin[$bytes]) & $mask)) {
                return false;
            }
        }
        return true;
    }
}
