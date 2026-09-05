<?php

declare(strict_types=1);

namespace Tests;

use DI\ContainerBuilder;
use Exception;
use PHPUnit\Framework\TestCase as PHPUnit_TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Headers;
use Slim\Psr7\Request as SlimRequest;
use Slim\Psr7\Uri;

class TestCase extends PHPUnit_TestCase
{
    use ProphecyTrait;

    private array $originalEnv;
    private string $testDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = $_ENV;
        $database = tempnam(sys_get_temp_dir(), 'deskhelper-unit-');
        if ($database === false) {
            throw new \RuntimeException('Cannot create isolated test database.');
        }
        $this->testDatabase = $database;
        $_ENV['APP_ENV'] = 'test';
        $_ENV['APP_BASE_PATH'] = '/deskhelper';
        $_ENV['APP_DB_PATH'] = $database;
        $_ENV['ADMIN_USER'] = 'admin';
        $_ENV['ADMIN_PASS'] = 'admin123';
        $_ENV['DESKHELPER_ADMIN_EMAIL'] = 'admin@deskhelper.local';
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        $_SESSION = [];
        $_ENV = $this->originalEnv;
        foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
            if (is_file($this->testDatabase . $suffix)) {
                unlink($this->testDatabase . $suffix);
            }
        }
        parent::tearDown();
    }

    /**
     * @return App
     * @throws Exception
     */
    protected function getAppInstance(bool $registerApplicationRoutes = true): App
    {
        // Instantiate PHP-DI ContainerBuilder
        $containerBuilder = new ContainerBuilder();

        // Container intentionally not compiled for tests.

        // Set up settings
        $settings = require __DIR__ . '/../app/settings.php';
        $settings($containerBuilder);

        // Set up dependencies
        $dependencies = require __DIR__ . '/../app/dependencies.php';
        $dependencies($containerBuilder);

        // Set up repositories
        $repositories = require __DIR__ . '/../app/repositories.php';
        $repositories($containerBuilder);

        // Build PHP-DI Container instance
        $container = $containerBuilder->build();

        // Instantiate the app
        AppFactory::setContainer($container);
        $app = AppFactory::create();

        // Register middleware
        $middleware = require __DIR__ . '/../app/middleware.php';
        $middleware($app);

        // Register routes
        if ($registerApplicationRoutes) {
            $routes = require __DIR__ . '/../app/routes.php';
            $routes($app);
        }

        return $app;
    }

    /**
     * @param string $method
     * @param string $path
     * @param array  $headers
     * @param array  $cookies
     * @param array  $serverParams
     * @return Request
     */
    protected function createRequest(
        string $method,
        string $path,
        array $headers = ['HTTP_ACCEPT' => 'application/json'],
        array $cookies = [],
        array $serverParams = []
    ): Request {
        $basePath = trim((string) ($_ENV['APP_BASE_PATH'] ?? ''));
        $normalizedBasePath = $basePath === '' || $basePath === '/'
            ? ''
            : '/' . trim($basePath, '/');
        $normalizedPath = $path;

        if (
            $normalizedBasePath !== ''
            && ($normalizedPath === $normalizedBasePath || str_starts_with($normalizedPath, $normalizedBasePath . '/'))
        ) {
            $normalizedPath = substr($normalizedPath, strlen($normalizedBasePath)) ?: '/';
        }

        $uri = new Uri('', '', 80, $normalizedPath);
        $handle = fopen('php://temp', 'w+');
        $stream = (new StreamFactory())->createStreamFromResource($handle);

        $h = new Headers();
        foreach ($headers as $name => $value) {
            $h->addHeader($name, $value);
        }

        return new SlimRequest($method, $uri, $h, $cookies, $serverParams, $stream);
    }
}
