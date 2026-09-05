<?php

declare(strict_types=1);

namespace Tests\Application\Handlers;

use App\Application\Handlers\HttpErrorHandler;
use App\Application\Handlers\ShutdownHandler;
use Tests\TestCase;

class ShutdownHandlerTest extends TestCase
{
    /** @dataProvider nonFatalErrors */
    public function testNonFatalErrorDoesNotReplaceResponse(int $type): void
    {
        $errorHandler = $this->createMock(HttpErrorHandler::class);
        $errorHandler->expects($this->never())->method('__invoke');
        $handler = new ShutdownHandler($this->createRequest('GET', '/'), $errorHandler, false);

        set_error_handler(static fn (): bool => false);
        try {
            @trigger_error('Non-fatal regression fixture', $type);
            $this->assertSame($type, error_get_last()['type']);
            $handler();
        } finally {
            restore_error_handler();
            error_clear_last();
        }
    }

    public function nonFatalErrors(): array
    {
        return [[E_USER_WARNING], [E_USER_NOTICE], [E_USER_DEPRECATED]];
    }

    /** @dataProvider responseFormats */
    public function testFatalErrorStillEmitsServerErrorResponse(string $format): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/fatal-shutdown.php', $format],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertNotSame(0, proc_close($process));
        if ($format === 'application/json') {
            $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(500, $payload['statusCode']);
        } else {
            $this->assertStringContainsString('<!doctype html>', $output);
            $this->assertStringContainsString('Erro 500', $output);
            $this->assertStringContainsString('width: min(720px, 100%);', $output);
        }
        $this->assertStringNotContainsString('undefined_function', $output);
    }

    public function responseFormats(): array
    {
        return [['application/json'], ['text/html']];
    }
}
