<?php

declare(strict_types=1);

namespace Tests\Application\Actions\User;

use Tests\TestCase;

class ViewUserActionTest extends TestCase
{
    public function testDashboardSectionRouteRespondsWithSuccess()
    {
        $app = $this->getAppInstance();

        // Seed this legacy fixture explicitly; the test database starts empty.
        unlink($_ENV['APP_DB_PATH']);
        $app->getContainer()->get(\App\Infrastructure\Persistence\Dashboard\DashboardRepository::class);

        $request = $this->createRequest('GET', '/deskhelper/secao-1');
        $response = $app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('1ª Seção', (string) $response->getBody());
    }

    public function testInvalidDashboardSectionRedirectsToDashboardHome()
    {
        $app = $this->getAppInstance();

        $request = $this->createRequest('GET', '/deskhelper/secao-inexistente');
        $response = $app->handle($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/deskhelper', $response->getHeaderLine('Location'));
    }
}
