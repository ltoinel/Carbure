<?php

use PHPUnit\Framework\TestCase;

/**
 * Fake resource used to test the service dispatch.
 */
final class EchoResource
{
    public static function echo($first, $second = 'default')
    {
        return ['first' => $first, 'second' => $second];
    }
}

class WebserviceTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testErrorOutputsJson()
    {
        ob_start();
        Webservice::error(404, new Error("Route not found"));
        $output = json_decode(ob_get_clean(), true);

        $this->assertSame("Route not found", $output['error']);
        $this->assertSame(404, $output['code']);
        $this->assertSame(Logger::getUID(), $output['uid']);
    }

    public function testCallServiceUsesNamedParameters()
    {
        $response = json_decode(Webservice::callService('EchoResource', 'echo', ['second' => 'b', 'first' => 'a']), true);

        $this->assertSame(['first' => 'a', 'second' => 'b'], $response);
    }

    public function testCallServiceMergesQueryParameters()
    {
        $_GET = ['first' => 'from-query'];
        $response = json_decode(Webservice::callService('EchoResource', 'echo', []), true);

        $this->assertSame('from-query', $response['first']);
        $this->assertSame('default', $response['second']);
    }

    public function testCallServiceRejectsUnknownParameter()
    {
        $this->expectException(Error::class);
        Webservice::callService('EchoResource', 'echo', ['first' => 'a', 'unknown' => 'x']);
    }

    public function testSensitiveValuesAreMasked()
    {
        $method = new ReflectionMethod(Webservice::class, 'maskSensitive');
        $masked = $method->invoke(null, [
            'username' => 'bob',
            'password' => 'secret',
            'device' => ['name' => 'iPhone', 'token' => 'abcdef'],
        ]);

        $this->assertSame('bob', $masked['username']);
        $this->assertSame('***', $masked['password']);
        $this->assertSame('iPhone', $masked['device']['name']);
        $this->assertSame('***', $masked['device']['token']);
    }

    public function testPayload()
    {
        $this->assertSame([], Webservice::getPayload(''));
        $this->assertSame(['a' => 1], Webservice::getPayload('{"a":1}'));
        $this->assertSame([], Webservice::getPayload('null'));

        $this->expectException(Error::class);
        Webservice::getPayload('{invalid');
    }

    public function testExecUnknownRoute()
    {
        $_SERVER['REQUEST_URI'] = '/api/does/not/exist?x=1';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Webservice::exec();
    }

    public function testExecProtectedRouteWithoutToken()
    {
        $_SERVER['REQUEST_URI'] = '/api/transaction';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->expectException(Error::class);
        $this->expectExceptionCode(403);
        Webservice::exec();
    }

    public function testExecSyncWithoutTokenIsRefused()
    {
        $_SERVER['REQUEST_URI'] = '/api/bank/sync';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $level = ob_get_level();

        try {
            Webservice::exec();
            $this->fail('An error was expected');
        } catch (Error $e) {
            $this->assertSame(403, $e->getCode());
        } finally {
            // The stream headers close the output buffers: restore PHPUnit's
            while (ob_get_level() < $level) {
                ob_start();
            }
        }
    }

    public function testServerSentEvents()
    {
        ob_start();
        Webservice::sendProgress('Syncing');
        Webservice::sendHeartbeat();
        $this->assertSame("data: Syncing\n\n: heartbeat\n\n", ob_get_clean());
    }

    public function testApiEntryPoint()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Unknown route: JSON error
        $_SERVER['REQUEST_URI'] = '/api/does/not/exist';
        ob_start();
        include __DIR__ . '/../../src/api.php';
        $output = json_decode(ob_get_clean(), true);
        $this->assertSame(404, $output['code']);

        // Static files (with a dot) are not handled by the API
        $_SERVER['REQUEST_URI'] = '/portal/app.js';
        ob_start();
        include __DIR__ . '/../../src/api.php';
        $this->assertSame('', ob_get_clean());
    }

    public function testGetHeader()
    {
        $_SERVER['HTTP_X_SYNC_TOKEN'] = 'abc';
        $this->assertSame('abc', Webservice::getHeader('X-Sync-Token'));
        unset($_SERVER['HTTP_X_SYNC_TOKEN']);

        $this->assertNull(Webservice::getHeader('X-Sync-Token'));
    }
}
