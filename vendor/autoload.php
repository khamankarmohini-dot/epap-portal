<?php
/**
 * Composer-compatible PSR-4 autoloader for PHPMailer and App\
 */
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $map = [
        'PHPMailer\\PHPMailer\\' => __DIR__ . '/phpmailer/phpmailer/src/',
        'App\\'                  => dirname(__DIR__) . '/app/',
    ];

    foreach ($map as $prefix => $baseDir) {
        if (strpos($class, $prefix) === 0) {
            $rel = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $rel) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }
    }
});
