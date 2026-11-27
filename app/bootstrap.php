<?php
/**
 * EPAP - Application Bootstrap
 */
declare(strict_types=1);

define('EPAP_ROOT', dirname(__DIR__));

// Simple PSR-4 autoloader for App\ namespace
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = substr($class, strlen($prefix));
    $file = EPAP_ROOT . '/app/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use App\Core\Database;
use App\Core\Env;
use App\Core\Logger;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

// Optional local fallback (.env is never required in production)
Env::load(EPAP_ROOT . '/.env');

date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'Asia/Kolkata'));

ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (Env::isProduction() || Env::isRender()) {
    ini_set('error_log', 'php://stderr');
} else {
    $logDir = EPAP_ROOT . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    ini_set('error_log', $logDir . '/php_errors.log');
}

// Detect HTTPS honoring X-Forwarded-Proto only from TRUSTED_PROXIES
define('EPAP_IS_HTTPS', Request::isHttpsRequest());

// Central exception & error handlers for HTTP requests
if (PHP_SAPI !== 'cli') {
    set_exception_handler(function (Throwable $e): void {
        $errRef = bin2hex(random_bytes(4));
        Logger::error("Uncaught exception [ref={$errRef}]: " . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        if (!headers_sent()) {
            Response::error("An internal server error occurred. (Ref: {$errRef})", 500, ['error_ref' => $errRef]);
        }
    });

    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    if (Env::isLocal() && EPAP_IS_HTTPS) {
        Logger::info('Warning: APP_ENV is set to local while serving over HTTPS.');
    }

    // Production readiness check before login
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    if (Env::isProduction() && str_ends_with($reqPath, '/auth/login')) {
        if (!Mailer::isConfigured()) {
            $errRef = bin2hex(random_bytes(4));
            Logger::error("Production startup check failed [ref={$errRef}]: Mail driver (" . Mailer::driverName() . ') is not configured.');
            Response::error("Portal sign-in is unavailable: Email delivery is not configured. (Ref: {$errRef})", 503, ['error_ref' => $errRef]);
        }
        try {
            Database::getConnection()->query('SELECT 1');
        } catch (Throwable $e) {
            $errRef = bin2hex(random_bytes(4));
            Logger::error("Production startup check failed [ref={$errRef}]: Database unreachable (" . Database::classifyError($e) . ').');
            Response::error("Portal sign-in is unavailable: Database is unreachable. (Ref: {$errRef})", 503, ['error_ref' => $errRef]);
        }
    }

    // Hardened session configuration
    $envLifetime = (int) Env::get('SESSION_LIFETIME', '7200');
    $lifetime = (int) Settings::get('session_timeout_seconds', (string) $envLifetime);
    if ($lifetime < 300) {
        $lifetime = $envLifetime > 0 ? $envLifetime : 7200;
    }
    $secure = Env::get('SESSION_SECURE', 'false') === 'true' ? true : EPAP_IS_HTTPS;

    if (session_status() === PHP_SESSION_NONE) {
        session_name('EPAP_SESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        session_start();
    }

    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > $lifetime) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = $now;
}
