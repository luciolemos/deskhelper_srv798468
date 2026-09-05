<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

foreach (['APP_BASE_PATH', 'APP_DB_PATH', 'ADMIN_USER', 'ADMIN_PASS'] as $key) {
    $_ENV[$key] = (string) getenv($key);
}

$database = $_ENV['APP_DB_PATH'];
if ($database === '' || is_file($database) || getenv('APP_ENV') !== 'test') {
    throw new RuntimeException('Visual fixtures require a new, isolated test database.');
}

new App\Infrastructure\Persistence\Dashboard\DashboardRepository(
    $database,
    dirname(__DIR__) . '/app/content/dashboard.php'
);
