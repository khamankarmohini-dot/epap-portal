<?php
/**
 * Router for PHP built-in server:
 *   php -S localhost:3000 router.php
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$uri = '/' . ltrim($uri, '/');

// Block direct access to sensitive directories and files
$blockedPrefixes = ['/app/', '/storage/', '/database/', '/docs/', '/tests/', '/vendor/', '/dist/', '/bin/', '/docker/'];
foreach ($blockedPrefixes as $prefix) {
    if (stripos($uri, $prefix) === 0 || strcasecmp(rtrim($uri, '/'), rtrim($prefix, '/')) === 0) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden']);
        return true;
    }
}

$blockedFiles = ['/.env', '/.env.example', '/.htaccess', '/composer.json', '/composer.lock', '/setup.php', '/router.php', '/dockerfile', '/render.yaml'];
$ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
if (
    in_array(strtolower($uri), $blockedFiles, true)
    || str_starts_with(basename($uri), '.')
    || in_array($ext, ['sql', 'log'], true)
) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    return true;
}

// Route /api/* to api/index.php
if ($uri === '/api' || str_starts_with($uri, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

// Serve existing static files directly
$filePath = realpath(__DIR__ . $uri);
$rootPath = realpath(__DIR__);
if ($uri !== '/' && $filePath && str_starts_with($filePath, $rootPath) && is_file($filePath)) {
    return false;
}

// Root -> index.html
if ($uri === '/') {
    readfile(__DIR__ . '/index.html');
    return true;
}

// 404 for unknown paths
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>404 Not Found</h1><p><a href="/index.html">Return to EPAP Home</a></p></body></html>';
return true;
