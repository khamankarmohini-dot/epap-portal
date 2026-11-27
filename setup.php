<?php
/**
 * EPAP - Production Setup, Installer, Migrations & Self-Check
 *
 * Usage:
 *   php setup.php                     Initialize schema, migrations, and first admin
 *   php setup.php --migrate           Run pending numbered migrations safely
 *   php setup.php --with-practice     Load practice coding problems (content only)
 *   php setup.php --demo              Load optional demo dataset (local only, or --force-demo)
 *   php setup.php --reset             Drop and recreate database (requires typing DB name)
 *   php setup.php --check             Run production readiness & security self-check
 *   php setup.php --test-mail=EMAIL   Send a test email using .env SMTP settings
 *
 * Non-interactive admin flags:
 *   --admin-id=ID --admin-name="Full Name" --admin-email=a@b.com --admin-password=SecretPass123
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: Run setup.php from the command line.');
}

$root = __DIR__;
$args = array_slice($argv, 1);

function hasFlag(array $args, string $flag): bool
{
    return in_array($flag, $args, true);
}

function getOptVal(array $args, string $prefix, ?string $envKey = null): ?string
{
    foreach ($args as $a) {
        if (str_starts_with($a, $prefix . '=')) {
            return substr($a, strlen($prefix) + 1);
        }
    }
    if ($envKey !== null) {
        $v = getenv($envKey);
        if ($v !== false && $v !== '') {
            return (string) $v;
        }
    }
    return null;
}

function promptLine(string $label): string
{
    echo $label;
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

function promptHidden(string $label): string
{
    echo $label;
    if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
        $cmd = 'powershell -NoProfile -Command "$p = Read-Host -AsSecureString; '
            . '[Runtime.InteropServices.Marshal]::PtrToStringAuto('
            . '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
        $val = shell_exec($cmd);
        echo "\n";
        return $val === null ? '' : trim($val);
    }
    if (function_exists('posix_isatty') && @posix_isatty(STDIN)) {
        system('stty -echo');
        $line = fgets(STDIN);
        system('stty echo');
        echo "\n";
        return $line === false ? '' : trim($line);
    }
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

echo "=== EPAP Setup & Administration ===\n";

// 1. Check PHP Version
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    fwrite(STDERR, "ERROR: PHP 8.0+ (8.1+ recommended) is required. Found " . PHP_VERSION . "\n");
    exit(1);
}
echo "[OK] PHP " . PHP_VERSION . "\n";

// 2. Check Extensions
$requiredExts = ['pdo_mysql', 'mbstring', 'fileinfo', 'openssl', 'json'];
$missing = array_filter($requiredExts, fn($ext) => !extension_loaded($ext));
if ($missing) {
    fwrite(STDERR, "ERROR: Missing PHP extensions: " . implode(', ', $missing) . "\n");
    exit(1);
}
echo "[OK] Required PHP extensions loaded\n";

// 3. Check PHPMailer (required if MAIL_DRIVER=smtp)
$autoloadFile = $root . '/vendor/autoload.php';
if (is_file($autoloadFile)) {
    require_once $autoloadFile;
    echo "[OK] Composer autoloader present\n";
} else {
    echo "[WARN] vendor/autoload.php is missing (only required for MAIL_DRIVER=smtp)\n";
}

// 4. Ensure .env exists only for local non-container setups
$envFile = $root . '/.env';
$exampleFile = $root . '/.env.example';
if (!is_file($envFile) && is_file($exampleFile) && getenv('DB_HOST') === false && getenv('RENDER') === false) {
    copy($exampleFile, $envFile);
    echo "[OK] Created .env from .env.example\n";
}

// 5. Ensure storage directories exist (best-effort)
foreach (['storage/uploads', 'storage/logs', 'storage/mail', 'storage/backups'] as $dir) {
    $full = $root . '/' . $dir;
    if (!is_dir($full)) {
        @mkdir($full, 0775, true);
    }
}
echo "[OK] Storage directories ready\n";

// 6. Bootstrap application
require_once $root . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Core\Mailer;

// Handle --test-mail=EMAIL
$testMail = getOptVal($args, '--test-mail');
if ($testMail !== null) {
    if (!filter_var($testMail, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "ERROR: Invalid email address for --test-mail.\n");
        exit(1);
    }
    echo "Sending test email to {$testMail}...\n";
    if (Mailer::sendTestEmail($testMail)) {
        echo "[OK] Test email sent successfully.\n";
        exit(0);
    }
    fwrite(STDERR, "[FAILED] " . (Mailer::getLastError() ?? 'Unknown SMTP error') . "\n");
    exit(1);
}

// Handle --check
if (hasFlag($args, '--check')) {
    $issues = 0;
    $warnings = 0;
    echo "\n--- EPAP Startup & Security Self-Check ---\n";
    echo "APP_ENV: " . Env::get('APP_ENV', 'local') . "\n";

    try {
        $pdo = Database::getConnection();
        $pdo->query('SELECT 1');
        echo "[OK] Database connection successful (TLS=" . (strtolower((string) Env::get('DB_SSL', 'false')) === 'true' ? 'enabled' : 'off') . ")\n";
    } catch (Throwable $e) {
        echo "[FAIL] Database unreachable: " . Database::classifyError($e) . "\n";
        $issues++;
    }

    $mailDriver = Mailer::driverName();
    if ($mailDriver === 'smtp' && Env::isRender()) {
        echo "[WARN] MAIL_DRIVER=smtp on Render free tier will fail (ports 25/465/587 are blocked). Set MAIL_DRIVER=brevo_api.\n";
        $warnings++;
    }
    if (Mailer::isConfigured()) {
        echo "[OK] Mail driver '{$mailDriver}' is configured\n";
    } else {
        if (Env::isProduction()) {
            echo "[FAIL] Mail driver '{$mailDriver}' is NOT configured (required in APP_ENV=production)\n";
            $issues++;
        } else {
            echo "[WARN] Mail driver '{$mailDriver}' is not configured (local log fallback active)\n";
            $warnings++;
        }
    }

    $storageDriver = strtolower((string) Env::get('STORAGE_DRIVER', 'local'));
    if (Env::isRender() && $storageDriver !== 'db') {
        echo "[WARN] STORAGE_DRIVER={$storageDriver} is ephemeral on Render free tier. Set STORAGE_DRIVER=db.\n";
        $warnings++;
    } else {
        echo "[OK] Storage driver: {$storageDriver}\n";
    }

    if (is_file($envFile) && PHP_OS_FAMILY !== 'Windows') {
        $perms = fileperms($envFile) & 0777;
        if (($perms & 0004) !== 0) {
            echo "[WARN] .env is world-readable (" . decoct($perms) . "). Run: chmod 640 .env\n";
            $warnings++;
        } else {
            echo "[OK] .env permissions restricted (" . decoct($perms) . ")\n";
        }
    }

    if (!is_file($root . '/.htaccess') || !is_file($root . '/router.php')) {
        echo "[WARN] Missing .htaccess or router.php for sensitive folder protection\n";
        $warnings++;
    } else {
        echo "[OK] Web protection rules present (.htaccess + router.php)\n";
    }

    echo "\nCheck finished: {$issues} failure(s), {$warnings} warning(s).\n";
    exit($issues > 0 ? 1 : 0);
}

$dbName = (string) Env::get('DB_NAME', 'epap_db');
if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
    fwrite(STDERR, "ERROR: Invalid DB_NAME.\n");
    exit(1);
}

function stripCreateAndUseSql(string $sql): string
{
    $sql = preg_replace('/^\s*CREATE\s+DATABASE\b[^;]*;\s*/mi', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*USE\s+`?[A-Za-z0-9_]+`?\s*;\s*/mi', '', $sql) ?? $sql;
    return $sql;
}

// 7. Handle --reset safely or connect to existing DB
if (hasFlag($args, '--reset')) {
    try {
        $serverPdo = Database::getConnection(false);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: Cannot connect to MySQL server: " . Database::classifyError($e) . "\n");
        exit(1);
    }
    echo "\nWARNING: --reset will DROP database '{$dbName}' and permanently delete all data!\n";
    $confirm = promptLine("Type the database name ({$dbName}) to confirm: ");
    if ($confirm !== $dbName) {
        fwrite(STDERR, "Aborted: confirmation did not match '{$dbName}'.\n");
        exit(1);
    }
    $serverPdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "[OK] Recreated database '{$dbName}'\n";
}

try {
    $appPdo = Database::getConnection(true);
} catch (Throwable $e) {
    $msg = strtolower($e->getMessage());
    if (str_contains($msg, 'unknown database')) {
        $serverPdo = Database::getConnection(false);
        $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $appPdo = Database::getConnection(true);
    } else {
        fwrite(STDERR, "ERROR: Cannot connect to database '{$dbName}': " . Database::classifyError($e) . "\n");
        exit(1);
    }
}

// 8. Initialize base schema if fresh/empty database (works even when CREATE DATABASE is disallowed)
$hasUsers = false;
try {
    $hasUsers = (bool) $appPdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
} catch (Throwable $e) {
    $hasUsers = false;
}

if (!$hasUsers || !hasFlag($args, '--migrate')) {
    $schemaSql = file_get_contents($root . '/database/schema.sql');
    if ($schemaSql === false) {
        fwrite(STDERR, "ERROR: Cannot read database/schema.sql\n");
        exit(1);
    }
    $appPdo->exec(stripCreateAndUseSql($schemaSql));
    echo "[OK] Database '{$dbName}' base schema initialized\n";
}

// 9. Run numbered migrations
$migDir = $root . '/database/migrations';
if (is_dir($migDir)) {
    $files = glob($migDir . '/*.sql') ?: [];
    sort($files);
    $appliedStmt = $appPdo->query('SELECT migration FROM schema_migrations');
    $applied = $appliedStmt ? $appliedStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $insMig = $appPdo->prepare('INSERT IGNORE INTO schema_migrations (migration) VALUES (?)');
    $migCount = 0;
    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $applied, true)) {
            continue;
        }
        $sql = file_get_contents($file);
        if ($sql !== false && trim($sql) !== '') {
            $appPdo->exec($sql);
        }
        $insMig->execute([$name]);
        $migCount++;
        echo "[OK] Applied migration: {$name}\n";
    }
    if (hasFlag($args, '--migrate')) {
        echo $migCount === 0 ? "[OK] No pending migrations.\n" : "[OK] Applied {$migCount} migration(s).\n";
        if (!hasFlag($args, '--with-practice') && !hasFlag($args, '--demo')) {
            exit(0);
        }
    }
}

// 10. Optional practice problems seeder
if (hasFlag($args, '--with-practice')) {
    require_once $root . '/database/seed_practice.php';
    $added = runSeedPractice($appPdo);
    echo "[OK] Practice problems synced ({$added} new added)\n";
}

// 11. Optional demo seeder (refused in production unless --force-demo)
if (hasFlag($args, '--demo')) {
    if (Env::isProduction() && !hasFlag($args, '--force-demo')) {
        fwrite(STDERR, "ERROR: --demo is refused when APP_ENV=production. Pass --force-demo to override.\n");
        exit(1);
    }
    require_once $root . '/database/seed_demo.php';
    runSeedDemo($appPdo);
    echo "[OK] Optional demo dataset loaded\n";
}

// 12. First-run Admin creation (only if no admin exists)
$adminCount = (int) $appPdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
if ($adminCount === 0 && !hasFlag($args, '--demo')) {
    echo "\n--- Create First Administrator (HOD) ---\n";
    $adminName  = getOptVal($args, '--admin-name', 'EPAP_ADMIN_NAME') ?? getOptVal($args, '--admin-name', 'ADMIN_NAME');
    $adminId    = getOptVal($args, '--admin-id', 'EPAP_ADMIN_ID') ?? getOptVal($args, '--admin-id', 'ADMIN_ID');
    $adminEmail = getOptVal($args, '--admin-email', 'EPAP_ADMIN_EMAIL') ?? getOptVal($args, '--admin-email', 'ADMIN_EMAIL');
    $adminPass  = getOptVal($args, '--admin-password', 'EPAP_ADMIN_PASSWORD') ?? getOptVal($args, '--admin-password', 'ADMIN_PASSWORD');

    $isInteractive = ($adminName === null || $adminId === null || $adminEmail === null || $adminPass === null);

    if ($adminName === null) {
        $adminName = promptLine('Full Name: ');
    }
    if ($adminId === null) {
        $adminId = promptLine('Login ID (e.g. HOD-IT): ');
    }
    if ($adminEmail === null) {
        $adminEmail = promptLine('Email Address: ');
    }
    if ($adminPass === null) {
        $adminPass = promptHidden('Password (min 10 chars, letters + digits): ');
        $confirmPw = promptHidden('Confirm Password: ');
        if ($adminPass !== $confirmPw) {
            fwrite(STDERR, "ERROR: Passwords do not match.\n");
            exit(1);
        }
    }

    $adminName  = trim((string) $adminName);
    $adminId    = trim((string) $adminId);
    $adminEmail = trim((string) $adminEmail);
    $adminPass  = (string) $adminPass;

    if (strlen($adminName) < 2 || strlen($adminId) < 3) {
        fwrite(STDERR, "ERROR: Invalid admin name or login ID.\n");
        exit(1);
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "ERROR: Invalid admin email address.\n");
        exit(1);
    }
    if (strlen($adminPass) < 10 || !preg_match('/[A-Za-z]/', $adminPass) || !preg_match('/\d/', $adminPass)) {
        fwrite(STDERR, "ERROR: Password must be at least 10 characters and include both letters and numbers.\n");
        exit(1);
    }

    $stmt = $appPdo->prepare(
        "INSERT INTO users (identifier, password_hash, role, full_name, email, is_active, must_change_password)
         VALUES (?, ?, 'admin', ?, ?, 1, 0)"
    );
    $stmt->execute([$adminId, password_hash($adminPass, PASSWORD_BCRYPT), $adminName, $adminEmail]);
    echo "[OK] First administrator '{$adminId}' created.\n";

    if (!Mailer::isSmtpConfigured()) {
        echo "[WARN] SMTP is not configured in .env. Configure SMTP_* before enabling APP_ENV=production.\n";
    } elseif ($isInteractive) {
        $sendTest = strtolower(promptLine("Send a test OTP email to {$adminEmail} now? [y/N]: "));
        if ($sendTest === 'y' || $sendTest === 'yes') {
            if (Mailer::sendTestEmail($adminEmail)) {
                echo "[OK] Test email sent to {$adminEmail}.\n";
            } else {
                echo "[WARN] Test email failed: " . (Mailer::getLastError() ?? 'Unknown error') . "\n";
            }
        }
    }
} else {
    echo "[OK] Existing data preserved ({$adminCount} admin account(s) present)\n";
}

echo "\n=== Setup Complete ===\n";
echo "Next steps:\n";
echo "  1. Verify SMTP delivery : php bin/test-mail.php admin@yourdomain.edu\n";
echo "  2. Run readiness check  : php setup.php --check\n";
echo "  3. Start local server   : php -S localhost:3000 router.php\n";
echo "  4. Sign in at           : /login.html\n";
echo "  5. Configure Settings, then add Subjects -> Faculty -> Students -> Allocations.\n";
