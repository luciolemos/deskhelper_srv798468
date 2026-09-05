<?php

declare(strict_types=1);

foreach (['APP_ENV', 'APP_BASE_PATH', 'APP_DB_PATH', 'ADMIN_USER', 'ADMIN_PASS', 'DESKHELPER_ADMIN_EMAIL'] as $key) {
    $value = getenv($key);
    if ($value !== false) {
        $_ENV[$key] = $value;
    }
}

$basePath = '/' . trim($_ENV['APP_BASE_PATH'] ?? '/deskhelper', '/');
$publicDirectory = dirname(__DIR__) . '/public';
$requestPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($requestPath === $basePath) {
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ' . $basePath . '/' . ($query === '' ? '' : '?' . $query), true, 308);
    return;
}
if (!str_starts_with($requestPath, $basePath . '/')) {
    http_response_code(404);
    return;
}

$relativePath = substr($requestPath, strlen($basePath));
$asset = realpath($publicDirectory . $relativePath);
$mimeTypes = [
    'css' => 'text/css; charset=UTF-8', 'js' => 'application/javascript; charset=UTF-8',
    'svg' => 'image/svg+xml', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'webp' => 'image/webp', 'ico' => 'image/x-icon',
];
if ($asset !== false && str_starts_with($asset, $publicDirectory . '/') && is_file($asset)) {
    $extension = strtolower(pathinfo($asset, PATHINFO_EXTENSION));
    if (isset($mimeTypes[$extension])) {
        header('Content-Type: ' . $mimeTypes[$extension]);
        readfile($asset);
        return;
    }
}

$_SERVER['SCRIPT_NAME'] = $basePath . '/index.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME'] = $publicDirectory . '/index.php';
require $publicDirectory . '/index.php';
