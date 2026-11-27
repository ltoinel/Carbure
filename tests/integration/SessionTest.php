<?php

use Carbure\Lib\Db;
use Carbure\Lib\Jwt;
use Carbure\Resources\User;

/**
 * Server-side revocation of the JWT sessions (#533): password change, removed user,
 * "sign out everywhere"
 */
class SessionTest extends DatabaseTestCase
{
    /** Open a session as the client does, and return its token */
    private function session($username, $password)
    {
        $token = User::login($username, $password)['token'];
        $_SERVER['HTTP_AUTHORIZATION'] = "Bearer $token";
        return $token;
    }

    /** Whether a token still opens a protected route */
    private function accepted($token)
    {
        $_SERVER['HTTP_AUTHORIZATION'] = "Bearer $token";
        Jwt::forget();
        try {
            $this->callRoute('GET', '/user/me');
            return true;
        } catch (Error $e) {
            $this->assertSame(401, $e->getCode());
            return false;
        }
    }

    public function testATokenIsAcceptedWhileNothingIsRevoked()
    {
        $token = $this->session('user', 'userpass');
        $this->assertTrue(Jwt::checkAuthorization());
        $this->assertSame(self::USER, Jwt::getUserIdFromToken());
        $this->assertTrue($this->accepted($token));
        $this->assertNull(Db::queryOne("SELECT tokens_valid_after FROM users WHERE id = ?", "i", self::USER)['tokens_valid_after']);
    }

    public function testAPasswordChangeClosesTheOtherSessions()
    {
        $phone = $this->session('user', 'userpass');
        $portal = $this->session('user', 'userpass');

        $updated = User::update(self::USER, password: 'new-password');

        // The session that changed the password goes on with a new token
        $this->assertSame('user', $updated['username']);
        $this->assertSame(self::USER, $updated['session']['sub']);
        $this->assertFalse($this->accepted($phone));
        $this->assertFalse($this->accepted($portal));
        $this->assertTrue($this->accepted($updated['session']['token']));
        // A login right after the change, within the same second, opens a session
        $this->assertTrue($this->accepted($this->session('user', 'new-password')));
    }

    public function testAProfileChangeWithoutPasswordKeepsTheSessions()
    {
        $token = $this->session('user', 'userpass');
        $updated = User::update(self::USER, firstname: 'Camille', password: '');
        $this->assertArrayNotHasKey('session', $updated);
        $this->assertTrue($this->accepted($token));
    }

    public function testAnAdministratorResetsThePasswordOfAUser()
    {
        $user = $this->session('user', 'userpass');
        $admin = $this->session('admin', 'adminpass');

        $updated = User::update(self::USER, password: 'reset-by-admin');

        // No session of the user in the answer given to the administrator
        $this->assertArrayNotHasKey('session', $updated);
        $this->assertFalse($this->accepted($user));
        $this->assertTrue($this->accepted($admin));
    }

    public function testTheTokenOfADeletedUserIsRefused()
    {
        $user = $this->session('user', 'userpass');
        $this->session('admin', 'adminpass');
        Db::execute("DELETE FROM user_browser WHERE user_id = ?", "i", self::USER);
        User::delete(self::USER);
        $this->assertFalse($this->accepted($user));
    }

    public function testSignOutEverywhere()
    {
        $phone = $this->session('user', 'userpass');
        $admin = $this->session('admin', 'adminpass');
        $this->session('user', 'userpass');

        $result = json_decode($this->callRoute('DELETE', '/user/sessions'), true);

        $this->assertTrue($result['revoked']);
        $this->assertFalse($this->accepted($phone));
        $this->assertTrue($this->accepted($result['session']['token']));
        // The other members keep their sessions
        $this->assertTrue($this->accepted($admin));
    }

    public function testAnAdministratorSignsAUserOut()
    {
        $user = $this->session('user', 'userpass');
        $this->session('admin', 'adminpass');

        $result = User::signOutEverywhere(self::USER);

        $this->assertSame(['revoked' => true], $result);
        $this->assertFalse($this->accepted($user));
    }

    public function testAUserCannotSignAnotherOneOut()
    {
        $admin = $this->session('admin', 'adminpass');
        $this->session('user', 'userpass');
        try {
            User::signOutEverywhere(self::ADMIN);
            $this->fail('Accepted');
        } catch (Error $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertTrue($this->accepted($admin));
    }

    public function testSignOutOfAnUnknownUser()
    {
        $this->session('admin', 'adminpass');
        $this->expectExceptionCode(404);
        User::signOutEverywhere(9999);
    }

    public function testTheLockoutDoesNotCloseTheSessions()
    {
        // Wrong passwords typed by anyone must not sign the member out
        $token = $this->session('user', 'userpass');
        for ($i = 0; $i < 5; $i++) {
            try {
                User::login('user', 'wrong');
            } catch (Error $e) {
                // Refused, then locked
            }
        }
        $this->assertTrue($this->accepted($token));
    }

    public function testAnAdministratorWhoLosesTheRoleIsRefusedAtOnce()
    {
        Db::execute("UPDATE users SET is_admin = 1 WHERE id = ?", "i", self::USER);
        $token = $this->session('user', 'userpass');
        $this->callRoute('GET', '/system/config');
        Db::execute("UPDATE users SET is_admin = 0 WHERE id = ?", "i", self::USER);

        // Same token: the role is read from the database on each administrator route
        $this->assertTrue($this->accepted($token));
        $this->assertAdminOnly(['GET /system/config']);
    }

    public function testNothingIsRevokedBeforeTheMigration()
    {
        $token = $this->session('user', 'userpass');
        Db::query("ALTER TABLE users DROP COLUMN tokens_valid_after");
        Jwt::forget();
        try {
            // The password still changes, the sessions stay open
            $updated = User::update(self::USER, password: 'new-password');
            $this->assertArrayNotHasKey('session', $updated);
            $this->assertTrue($this->accepted($token));
            try {
                User::signOutEverywhere();
                $this->fail('Accepted');
            } catch (Error $e) {
                $this->assertSame(409, $e->getCode());
            }
        } finally {
            Db::query("ALTER TABLE users ADD COLUMN tokens_valid_after int(10) unsigned DEFAULT NULL");
            Jwt::forget();
        }
    }
}
