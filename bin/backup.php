<?php
/**
 * EPAP - Database Backup Utility
 * Usage: php bin/backup.php [--keep=14]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: CLI only.');
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Env;

$keep = 14;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--keep=')) {
        $keep = max(1, (int) substr($arg, 7));
    }
}

$backupDir = EPAP_ROOT . '/storage/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0775, true);
}

$host = (string) Env::get('DB_HOST', '127.0.0.1');
$port = (string) Env::get('DB_PORT', '3306');
$name = (string) Env::get('DB_NAME', 'epap_db');
$user = (string) Env::get('DB_USER', 'root');
$pass = (string) Env::get('DB_PASS', '');

$outFile = $backupDir . '/epap_' . $name . '_' . date('Ymd_His') . '.sql';

// Locate mysqldump if available
$mysqldump = null;
$candidates = ['mysqldump', 'C:\\xampp\\mysql\\bin\\mysqldump.exe', 'C:\\xamp 2\\mysql\\bin\\mysqldump.exe'];
foreach ($candidates as $cand) {
    if ($cand === 'mysqldump') {
        $which = stripos(PHP_OS_FAMILY, 'Windows') !== false ? shell_exec('where mysqldump 2>NUL') : shell_exec('command -v mysqldump 2>/dev/null');
        if ($which && trim($which) !== '') {
            $mysqldump = trim(explode("\n", trim($which))[0]);
            break;
        }
    } elseif (is_file($cand)) {
        $mysqldump = $cand;
        break;
    }
}

$dumped = false;
if ($mysqldump !== null) {
    $env = $_ENV;
    if ($pass !== '') {
        $env['MYSQL_PWD'] = $pass;
    }
    $cmd = sprintf(
        '"%s" --host=%s --port=%s --user=%s --single-transaction --routines --triggers %s > "%s"',
        $mysqldump,
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg($user),
        escapeshellarg($name),
        $outFile
    );
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (is_resource($proc)) {
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code === 0 && is_file($outFile) && filesize($outFile) > 0) {
            $dumped = true;
            echo "[OK] mysqldump backup written: {$outFile}\n";
        } else {
            @unlink($outFile);
            echo "[WARN] mysqldump failed ({$err}); falling back to PDO export.\n";
        }
    }
}

if (!$dumped) {
    $pdo = Database::getConnection();
    $fh = fopen($outFile, 'wb');
    if (!$fh) {
        fwrite(STDERR, "ERROR: Cannot write backup file {$outFile}\n");
        exit(1);
    }
    fwrite($fh, "-- EPAP PDO SQL Backup (" . date('c') . ")\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n" . $createRow[1] . ";\n\n");
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $cols = implode('`,`', array_keys($r));
            $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($r)));
            fwrite($fh, "INSERT INTO `{$table}` (`{$cols}`) VALUES ({$vals});\n");
        }
        fwrite($fh, "\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
    echo "[OK] PDO fallback backup written: {$outFile}\n";
}

// Enforce retention count
$backups = glob($backupDir . '/epap_*.sql') ?: [];
rsort($backups);
foreach (array_slice($backups, $keep) as $old) {
    @unlink($old);
    echo "[OK] Pruned old backup: " . basename($old) . "\n";
}
exit(0);
