<?php

use PHPUnit\Framework\TestCase;

class ApiResolverTest extends TestCase
{
    public function testLoginIsPublic()
    {
        $route = ApiResolver::findRoute('/user/login', 'POST');

        $this->assertSame('User', $route['class']);
        $this->assertSame('login', $route['method']);
        $this->assertTrue($route['apiRoute']->public);
    }

    public function testSyncIsAStream()
    {
        $route = ApiResolver::findRoute('/bank/sync', 'GET');

        $this->assertSame('getSync', $route['method']);
        $this->assertTrue($route['apiRoute']->stream);
    }

    public function testUnknownRoute()
    {
        $this->assertNull(ApiResolver::findRoute('/does/not/exist', 'GET'));
        $this->assertNull(ApiResolver::findRoute('/user/login', 'GET'));
    }

    public function testClearRouteCacheRebuildsTheRoutes()
    {
        ApiResolver::clearRouteCache();
        $this->assertNotNull(ApiResolver::findRoute('/user/me', 'GET'));
    }

    public function testOnlyExpectedRoutesArePublic()
    {
        $public = [];
        foreach (glob(__DIR__ . '/../../src/resources/*.php') as $file) {
            $class = new ReflectionClass(basename($file, '.php'));
            foreach ($class->getMethods() as $method) {
                foreach ($method->getAttributes(ApiRoute::class) as $attribute) {
                    $route = $attribute->newInstance();
                    if ($route->public) {
                        $public[] = $route->method . ' ' . $route->path;
                    }
                }
            }
        }
        sort($public);

        // Any new public route must be a deliberate decision
        $this->assertSame(['GET /bank/sync', 'POST /user/login'], $public);
    }
}
