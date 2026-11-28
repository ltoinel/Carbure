<?php

use Carbure\Lib\Db;
use Carbure\Lib\Jwt;
use Carbure\Lib\SessionCookie;
use Carbure\Lib\Setting;
use Carbure\Lib\Totp;
use Carbure\Resources\Mcp;
use Carbure\Resources\Mfa;
use Carbure\Resources\User;

/**
 * Session of the web portal in an HttpOnly cookie (#535): the portal never sees the token,
 * the other clients keep the Authorization header
 */
class SessionCookieTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        $_COOKIE = [];
        unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_X_FORWARDED_HOST']);
        SessionCookie::reset();
        parent::tearDown();
    }

    /** Log in as the portal does: the browser keeps the cookie of the answer */
    private function portalLogin($username, $password)
    {
        $session = User::login($username, $password, null, true, true);
        $this->assertTrue($session['cookie']);
        $this->assertArrayNotHasKey('token', $session);
        $this->keepCookie();
        return $session;
    }

    /** The browser stores the cookie sent by the last answer */
    private function keepCookie()
    {
        $token = SessionCookie::sent();
        $this->assertMatchesRegularExpression('/^[\w-]+\.[\w-]+\.[\w-]+$/', (string)$token);
        $_COOKIE[SessionCookie::NAME] = $token;
        SessionCookie::reset();
        return $token;
    }

    /** HTTP status of a call (200 when it runs) */
    private function httpStatus($method, $path, $params = [])
    {
        Jwt::forget();
        try {
            $this->callRoute($method, $path, $params);
            return 200;
        } catch (Error $e) {
            return $e->getCode();
        }
    }

    public function testThePortalGetsItsSessionInACookieOnly()
    {
        $session = $this->portalLogin('user', 'userpass');
        $this->assertSame(self::USER, $session['sub']);
        $this->assertGreaterThan(time(), $session['exp']);

        // The cookie opens the protected routes, without any header
        $this->assertArrayNotHasKey('HTTP_AUTHORIZATION', $_SERVER);
        $me = json_decode($this->callRoute('GET', '/user/me'), true);
        $this->assertSame('user', $me['username']);
        $this->assertTrue(Jwt::viaCookie());
    }

    public function testTheOtherClientsStillGetTheToken()
    {
        $session = User::login('user', 'userpass');
        $this->assertArrayHasKey('token', $session);
        $this->assertArrayNotHasKey('cookie', $session);
        $this->assertNull(SessionCookie::sent());
    }

    public function testTheHeaderComesBeforeTheCookie()
    {
        $this->portalLogin('user', 'userpass');
        $this->loginAs(self::ADMIN);
        $me = json_decode($this->callRoute('GET', '/user/me'), true);
        $this->assertSame('admin', $me['username']);
        $this->assertFalse(Jwt::viaCookie());
    }

    public function testAWriteWithTheCookieMustComeFromThePortal()
    {
        $this->portalLogin('user', 'userpass');
        $write = ['PUT', '/user', ['id' => self::USER, 'firstname' => 'Camille']];

        // Reads are always allowed
        $this->assertSame(200, $this->httpStatus('GET', '/user/me'));

        // No sign of the origin: refused
        $this->assertSame(401, $this->httpStatus(...$write));

        // Fetch metadata of the browser
        foreach (['cross-site' => 401, 'same-site' => 401, 'none' => 401, 'same-origin' => 200] as $site => $status) {
            $_SERVER['HTTP_SEC_FETCH_SITE'] = $site;
            $this->assertSame($status, $this->httpStatus(...$write), $site);
        }
        unset($_SERVER['HTTP_SEC_FETCH_SITE']);

        // Origin header of an older browser: the host of the server, or of its reverse proxy
        $_SERVER['HTTP_HOST'] = 'carbure.home';
        foreach (['https://evil.example' => 401, 'null' => 401, 'https://carbure.home' => 200, 'http://CARBURE.home:8080' => 200] as $origin => $status) {
            $_SERVER['HTTP_ORIGIN'] = $origin;
            $this->assertSame($status, $this->httpStatus(...$write), $origin);
        }
        $_SERVER['HTTP_HOST'] = 'carbure:80';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'budget.example.org';
        $_SERVER['HTTP_ORIGIN'] = 'https://budget.example.org';
        $this->assertSame(200, $this->httpStatus(...$write));

        $this->assertSame('Camille', Db::queryOne("SELECT firstname FROM users WHERE id = ?", "i", self::USER)['firstname']);
    }

    public function testAWriteWithTheHeaderNeedsNoOrigin()
    {
        $this->loginAs(self::USER);
        $this->assertSame(200, $this->httpStatus('PUT', '/user', ['id' => self::USER, 'firstname' => 'Camille']));
    }

    public function testAnInvalidCookieIsRefused()
    {
        foreach (['not-a-jwt', 'a.b.c', ''] as $value) {
            $_COOKIE[SessionCookie::NAME] = $value;
            $this->assertSame(401, $this->httpStatus('GET', '/user/me'), $value);
        }
    }

    public function testSignOutEverywhereRenewsTheCookieOfThePortal()
    {
        $this->portalLogin('user', 'userpass');
        $old = $_COOKIE[SessionCookie::NAME];
        $phone = User::login('user', 'userpass')['token'];
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';

        $result = json_decode($this->callRoute('DELETE', '/user/sessions'), true);

        // No token in the answer: the new session is in the cookie
        $this->assertTrue($result['session']['cookie']);
        $this->assertArrayNotHasKey('token', $result['session']);
        $new = $this->keepCookie();
        $this->assertNotSame($old, $new);
        $this->assertSame(200, $this->httpStatus('GET', '/user/me'));

        $_COOKIE[SessionCookie::NAME] = $old;
        $this->assertSame(401, $this->httpStatus('GET', '/user/me'));
        $_COOKIE = [];
        $_SERVER['HTTP_AUTHORIZATION'] = "Bearer $phone";
        $this->assertSame(401, $this->httpStatus('GET', '/user/me'));
    }

    public function testAPasswordChangeRenewsTheCookieOfThePortal()
    {
        $this->portalLogin('user', 'userpass');
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';

        $updated = json_decode($this->callRoute('PUT', '/user', ['id' => self::USER, 'password' => 'new-password']), true);

        $this->assertTrue($updated['session']['cookie']);
        $this->assertArrayNotHasKey('token', $updated['session']);
        $this->keepCookie();
        $this->assertSame(200, $this->httpStatus('GET', '/user/me'));
    }

    public function testLogoutRemovesTheCookie()
    {
        $this->portalLogin('user', 'userpass');
        $this->assertSame(['signed_out' => true], json_decode($this->callRoute('POST', '/user/logout'), true));
        $this->assertSame('', SessionCookie::sent());

        // Also without a session (expired, or already signed out)
        $_COOKIE = [];
        SessionCookie::reset();
        $this->assertSame(['signed_out' => true], json_decode($this->callRoute('POST', '/user/logout'), true));
        $this->assertSame('', SessionCookie::sent());
    }

    public function testTheSecondFactorAlsoGivesTheCookie()
    {
        $this->loginAs(self::USER);
        $secret = Mfa::setup()['secret'];
        Mfa::enable(Totp::code($secret, time()));
        unset($_SERVER['HTTP_AUTHORIZATION']);
        Db::execute("UPDATE user_mfa SET last_step = NULL WHERE user_id = ?", "i", self::USER);

        $challenge = User::login('user', 'userpass', null, true, true);
        $this->assertArrayHasKey('mfa_token', $challenge);
        $this->assertNull(SessionCookie::sent());

        $session = Mfa::login($challenge['mfa_token'], Totp::code($secret, time()), false, null, true);
        $this->assertTrue($session['cookie']);
        $this->assertArrayNotHasKey('token', $session);
        $this->keepCookie();
        $this->assertSame(200, $this->httpStatus('GET', '/user/me'));
    }

    public function testTheMcpServerIgnoresTheCookie()
    {
        Setting::set('mcp_enabled', '1');
        $this->portalLogin('admin', 'adminpass');
        try {
            Mcp::handle('2.0', 'ping', 1);
            $this->fail('Accepted');
        } catch (Error $e) {
            $this->assertSame(401, $e->getCode());
        }
    }

    public function testCookiePath()
    {
        foreach (['/api/user/login' => '/api', '/carbure/api/user/login' => '/carbure/api', '/api' => '/api', '/elsewhere' => '/'] as $uri => $path) {
            $_SERVER['REQUEST_URI'] = $uri . '?x=1';
            $this->assertSame($path, SessionCookie::path(), $uri);
        }
    }
}
