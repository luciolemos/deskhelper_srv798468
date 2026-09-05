<?php

declare(strict_types=1);

namespace Tests\Application\Actions\User;

use Tests\TestCase;

class ListUserActionTest extends TestCase
{
    public function testDashboardHomeRouteRespondsWithSuccess()
    {
        $app = $this->getAppInstance();

        $request = $this->createRequest('GET', '/deskhelper');
        $response = $app->handle($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertSame('/deskhelper/login', $response->getHeaderLine('Location'));
    }
}
