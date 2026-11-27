<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

class Settings
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        'institution_name'            => 'Zeal College of Engineering and Research, Narhe, Pune',
        'department_name'             => 'Department of Information Technology',
        'short_logo_text'             => 'ZCOER - IT',
        'academic_year'               => '2025-26',
        'current_semester'            => '5',
        'support_email'               => '',
        'session_timeout_seconds'     => '7200',
        'upload_max_mb'               => '10',
        'at_risk_sgpa_threshold'      => '6.50',
        'at_risk_timely_threshold'    => '60',
        'send_account_ready_email'    => '1',
        'include_temp_password_email' => '0',
        'benchmark_label'             => 'Department Benchmark',
        'curriculum_label'            => 'University Curriculum',
    ];

    public static function all(?PDO $pdo = null): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $merged = self::DEFAULTS;
        try {
            $pdo = $pdo ?? Database::getConnection();
            $stmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
            if ($stmt) {
                foreach ($stmt->fetchAll() as $row) {
                    $k = (string) ($row['setting_key'] ?? '');
                    if ($k !== '' && array_key_exists($k, $merged)) {
                        $merged[$k] = (string) ($row['setting_value'] ?? '');
                    }
                }
            }
        } catch (\Throwable $e) {
            // Table may not exist yet during early setup or health check
        }
        self::$cache = $merged;
        return $merged;
    }

    public static function get(string $key, ?string $fallback = null): string
    {
        $all = self::all();
        if (isset($all[$key]) && $all[$key] !== '') {
            return $all[$key];
        }
        return $fallback ?? (self::DEFAULTS[$key] ?? '');
    }

    public static function setMany(PDO $pdo, array $values): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($values as $k => $v) {
            if (array_key_exists($k, self::DEFAULTS)) {
                $stmt->execute([$k, (string) $v]);
            }
        }
        self::$cache = null;
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    public static function publicSettings(?PDO $pdo = null): array
    {
        $all = self::all($pdo);
        return [
            'institution_name'  => $all['institution_name'],
            'department_name'   => $all['department_name'],
            'short_logo_text'   => $all['short_logo_text'],
            'academic_year'     => $all['academic_year'],
            'current_semester'  => (int) $all['current_semester'],
            'support_email'     => $all['support_email'],
            'upload_max_mb'     => (int) $all['upload_max_mb'],
            'benchmark_label'   => $all['benchmark_label'],
            'curriculum_label'  => $all['curriculum_label'],
        ];
    }
}
