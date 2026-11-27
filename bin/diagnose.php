<?php
/**
 * EPAP - Environment & Runtime Diagnostics (CLI Only, Never Exposes Secrets)
 * Usage: php bin/diagnose.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: CLI only.');
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Core\Mailer;

echo "=== EPAP Diagnostics ===\n";
echo "PHP Version   : " . PHP_VERSION . "\n";
echo "APP_ENV       : " . Env::get('APP_ENV', 'local') . "\n";
echo "APP_URL       : " . (Env::get('APP_URL', '') ?: '(not set)') . "\n";
echo "Render Host   : " . (Env::isRender() ? 'yes' : 'no') . "\n\n";

echo "--- Extensions ---\n";
foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'openssl', 'curl', 'zip', 'json'] as $ext) {
    echo sprintf("  %-12s : %s\n", $ext, extension_loaded($ext) ? 'OK' : 'MISSING');
}

echo "\n--- Environment Variables (presence only) ---\n";
$vars = [
    'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_SSL', 'DB_SSL_VERIFY',
    'MAIL_DRIVER', 'MAIL_FROM_ADDRESS', 'BREVO_API_KEY', 'SMTP_HOST',
    'STORAGE_DRIVER', 'TRUSTED_PROXIES', 'HEALTH_KEY',
];
foreach ($vars as $k) {
    $v = Env::get($k);
    $status = ($v !== null && $v !== '') ? 'SET' : 'MISSING/EMPTY';
    echo sprintf("  %-18s : %s\n", $k, $status);
}

echo "\n--- Database ---\n";
try {
    $pdo = Database::getConnection();
    $pdo->query('SELECT 1');
    echo "  Connection    : OK (TLS=" . (strtolower((string) Env::get('DB_SSL', 'false')) === 'true' ? 'enabled' : 'off') . ")\n";

    $migDir = EPAP_ROOT . '/database/migrations';
    $diskMigs = is_dir($migDir) ? array_map('basename', glob($migDir . '/*.sql') ?: []) : [];
    $applied = [];
    try {
        $applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        echo "  Migrations    : schema_migrations table missing\n";
    }
    $pending = array_diff($diskMigs, $applied);
    echo "  Migrations    : " . count($applied) . " applied, " . count($pending) . " pending\n";

    try {
        $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        echo "  Admin Account : " . ($admins > 0 ? "OK ({$admins} present)" : 'NONE') . "\n";
    } catch (Throwable $e) {
        echo "  Admin Account : users table missing\n";
    }
} catch (Throwable $e) {
    echo "  Connection    : FAIL - " . Database::classifyError($e) . "\n";
}

echo "\n--- Mail Driver ---\n";
$driver = Mailer::driverName();
echo "  Driver        : {$driver}\n";
echo "  Configured    : " . (Mailer::isConfigured() ? 'YES' : 'NO') . "\n";
if ($driver === 'smtp' && Env::isRender()) {
    echo "  [WARN] MAIL_DRIVER=smtp on Render free tier will fail (ports 25/465/587 are blocked). Use MAIL_DRIVER=brevo_api.\n";
}

echo "\n--- Storage Driver ---\n";
$storage = strtolower((string) Env::get('STORAGE_DRIVER', 'local'));
echo "  Driver        : {$storage}\n";
echo "  Durable       : " . ($storage === 'db' ? 'YES (MySQL MEDIUMBLOB)' : 'NO (local disk; ephemeral on Render)') . "\n";
