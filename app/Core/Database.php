<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;
    private static ?string $tempCaFile = null;

    public static function getConnection(bool $withDb = true): PDO
    {
        if ($withDb && self::$pdo !== null) {
            try {
                self::$pdo->query('SELECT 1');
                return self::$pdo;
            } catch (PDOException $e) {
                if (!self::isGoneAway($e)) {
                    throw $e;
                }
                self::$pdo = null; // Retry once on "server has gone away"
            }
        }

        $host = (string) Env::get('DB_HOST', '127.0.0.1');
        $port = (string) Env::get('DB_PORT', '3306');
        $name = (string) Env::get('DB_NAME', 'epap_db');
        $user = (string) Env::get('DB_USER', 'root');
        $pass = (string) Env::get('DB_PASS', '');

        $dsn = $withDb
            ? "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4"
            : "mysql:host={$host};port={$port};charset=utf8mb4";

        $options = self::buildPdoOptions();

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            if (self::isGoneAway($e)) {
                $pdo = new PDO($dsn, $user, $pass, $options);
            } else {
                throw $e;
            }
        }

        if ($withDb) {
            self::$pdo = $pdo;
        }
        return $pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function buildPdoOptions(): array
    {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::ATTR_TIMEOUT            => (int) Env::get('DB_TIMEOUT', '5'),
        ];

        $sslEnabled = strtolower((string) Env::get('DB_SSL', 'false')) === 'true';
        if ($sslEnabled) {
            $verify = strtolower((string) Env::get('DB_SSL_VERIFY', 'true')) !== 'false';
            $caPath = self::resolveCaPath();

            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $verify;
            }
            if ($caPath !== null && defined('PDO::MYSQL_ATTR_SSL_CA')) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
            }
        }

        return $options;
    }

    public static function resolveCaPath(): ?string
    {
        $caRaw = trim((string) Env::get('DB_SSL_CA', ''));
        if ($caRaw !== '') {
            if (str_contains($caRaw, '-----BEGIN')) {
                if (self::$tempCaFile === null || !is_file(self::$tempCaFile)) {
                    $tmp = sys_get_temp_dir() . '/epap-db-ca.pem';
                    @file_put_contents($tmp, str_replace('\n', "\n", $caRaw));
                    self::$tempCaFile = $tmp;
                }
                return self::$tempCaFile;
            }
            if (is_file($caRaw)) {
                return $caRaw;
            }
        }

        $verify = strtolower((string) Env::get('DB_SSL_VERIFY', 'true')) !== 'false';
        if ($verify) {
            foreach ([
                '/etc/ssl/certs/ca-certificates.crt',
                '/etc/pki/tls/certs/ca-bundle.crt',
                '/etc/ssl/cert.pem',
            ] as $sysCa) {
                if (is_file($sysCa)) {
                    return $sysCa;
                }
            }
        }
        return null;
    }

    public static function classifyError(\Throwable $e): string
    {
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'access denied')) {
            return 'Authentication failed (check DB_USER / DB_PASS)';
        }
        if (str_contains($msg, 'unknown database')) {
            return 'Unknown database (check DB_NAME)';
        }
        if (str_contains($msg, 'tls') || str_contains($msg, 'ssl') || str_contains($msg, 'certificate')) {
            return 'TLS/SSL handshake or certificate error (check DB_SSL / DB_SSL_CA / DB_SSL_VERIFY)';
        }
        if (str_contains($msg, 'refused') || str_contains($msg, 'timed out') || str_contains($msg, 'name or service not known') || str_contains($msg, 'getaddrinfo')) {
            return 'Host unreachable or timed out (check DB_HOST / DB_PORT / firewall)';
        }
        return $e->getMessage();
    }

    private static function isGoneAway(PDOException $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'gone away') || str_contains($msg, 'lost connection') || str_contains($msg, '2006') || str_contains($msg, '2013');
    }
}
