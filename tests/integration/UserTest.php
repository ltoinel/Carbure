<?php

class UserTest extends DatabaseTestCase
{
    public function testLogin()
    {
        $token = User::login('admin', 'adminpass');

        $this->assertSame(self::ADMIN, Jwt::parseJwt($token['token'])->sub);
    }

    public function testLoginRegistersTheDevice()
    {
        User::login('user', 'userpass', ['name' => 'iPad', 'token' => str_repeat('b', 64)]);
        User::login('user', 'userpass', ['name' => 'iPad Pro', 'token' => str_repeat('b', 64)]);

        $this->loginAs(self::USER);
        $devices = Device::getMine();
        $this->assertCount(1, $devices);
        $this->assertSame('iPad Pro', $devices[0]['name']);
    }

    public function testLoginWithWrongPassword()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(401);
        User::login('admin', 'wrong');
    }

    public function testLoginWithUnknownUser()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(401);
        User::login('nobody', 'adminpass');
    }

    public function testLegacyPasswordIsUpgraded()
    {
        User::login('legacy', 'legacypass');

        $hash = Db::queryOne("SELECT password FROM users WHERE id=3", "")['password'];
        $this->assertTrue(password_verify('legacypass', $hash));

        // Still works with the new hash, wrong password still rejected
        User::login('legacy', 'legacypass');
        $this->expectException(Error::class);
        User::login('legacy', 'wrong');
    }

    public function testLegacyPasswordWrong()
    {
        $this->expectException(Error::class);
        User::login('legacy', 'wrong');
    }

    public function testOutdatedHashIsRehashed()
    {
        Db::execute("UPDATE users SET password=? WHERE id=2", "s", password_hash('userpass', PASSWORD_BCRYPT, ['cost' => 4]));

        User::login('user', 'userpass');

        $hash = Db::queryOne("SELECT password FROM users WHERE id=2", "")['password'];
        $this->assertFalse(password_needs_rehash($hash, PASSWORD_DEFAULT));
    }

    public function testMe()
    {
        $this->loginAs(self::USER);
        $me = User::me();

        $this->assertSame('user', $me['username']);
        $this->assertSame('en', $me['language']);
        $this->assertArrayNotHasKey('password', $me);
    }

    public function testMeDeletedUser()
    {
        $this->loginAs(99);
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        User::me();
    }

    public function testAdminListsAllUsers()
    {
        $this->loginAs(self::ADMIN);
        $this->assertCount(3, User::get());
    }

    public function testUserOnlySeesHimself()
    {
        $this->loginAs(self::USER);
        $users = User::get();

        $this->assertCount(1, $users);
        $this->assertSame('user', $users[0]['username']);
    }

    public function testCreate()
    {
        $this->loginAs(self::ADMIN);
        $user = User::create('new', 'newpass', 'new@example.com', 'New');

        $this->assertSame('new', $user['username']);
        $this->assertSame($user['id'], User::login('new', 'newpass')['sub']);
    }

    public function testCreateDuplicateUsername()
    {
        $this->loginAs(self::ADMIN);
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Username already exists');
        User::create('user', 'x', 'other@example.com');
    }

    public function testCreateDuplicateEmail()
    {
        $this->loginAs(self::ADMIN);
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Email already exists');
        User::create('other', 'x', 'user@example.com');
    }

    public function testCreateRequiresAdmin()
    {
        $this->loginAs(self::USER);
        $this->expectException(Error::class);
        $this->expectExceptionCode(403);
        User::create('new', 'x', 'new@example.com');
    }

    public function testDelete()
    {
        $this->loginAs(self::ADMIN);
        $this->assertTrue(User::delete(self::LEGACY));
        $this->assertCount(2, User::get());
    }

    public function testDeleteRequiresAdmin()
    {
        $this->loginAs(self::USER);
        $this->expectException(Error::class);
        $this->expectExceptionCode(403);
        User::delete(self::LEGACY);
    }

    public function testDeleteSelf()
    {
        $this->loginAs(self::ADMIN);
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        User::delete(self::ADMIN);
    }

    public function testDeleteUnknown()
    {
        $this->loginAs(self::ADMIN);
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        User::delete(99);
    }

    public function testDeleteOwnerOfTransactions()
    {
        Db::query("UPDATE bank_transaction SET user=3 WHERE uuid='u1'");
        $this->loginAs(self::ADMIN);

        $this->expectException(Error::class);
        $this->expectExceptionCode(409);
        User::delete(self::LEGACY);
    }

    public function testUpdateOwnProfile()
    {
        $this->loginAs(self::USER);
        $user = User::update(self::USER, 'ugo@example.com', 'Hugo', 'Utilisateur', 'newpass', 'fr');

        $this->assertSame('ugo@example.com', $user['email']);
        $this->assertSame('Hugo', $user['firstname']);
        $this->assertSame('fr', $user['language']);
        User::login('user', 'newpass');
    }

    public function testAlertThreshold()
    {
        $this->loginAs(self::USER);

        $this->assertEquals(150.5, User::update(self::USER, null, null, null, null, null, '150.5')['alert_threshold']);
        $this->assertNull(User::update(self::USER, null, null, null, null, null, '')['alert_threshold']);
        $this->assertNull(User::update(self::USER, null, null, null, null, null, 0)['alert_threshold']);

        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        User::update(self::USER, null, null, null, null, null, '-5');
    }

    public function testUpdateNothing()
    {
        $this->loginAs(self::USER);
        $user = User::update(self::USER);

        $this->assertSame('user@example.com', $user['email']);
    }

    public function testUpdateInvalidLanguage()
    {
        $this->loginAs(self::USER);
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        User::update(self::USER, null, null, null, null, 'de');
    }

    public function testUpdateDuplicateEmail()
    {
        $this->loginAs(self::USER);
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Email already exists');
        User::update(self::USER, 'admin@example.com');
    }

    public function testRoles()
    {
        $this->loginAs(self::ADMIN);
        $created = User::create('admin2', 'password123', 'admin2@example.org', null, null, true);
        $this->assertSame(1, $created['is_admin']);

        // Demote then promote a user
        User::update($created['id'], null, null, null, null, null, null, false);
        $this->assertEquals(0, Db::queryOne("SELECT is_admin FROM users WHERE id = ?", "i", $created['id'])['is_admin']);
        User::update(self::USER, null, null, null, null, null, null, true);
        $this->assertEquals(1, Db::queryOne("SELECT is_admin FROM users WHERE id = ?", "i", self::USER)['is_admin']);
    }

    public function testLastAdministratorKeepsTheRole()
    {
        $this->loginAs(self::ADMIN);
        $this->expectException(Error::class);
        $this->expectExceptionCode(409);
        User::update(self::ADMIN, null, null, null, null, null, null, false);
    }

    public function testUserCannotChangeHisRole()
    {
        $this->loginAs(self::USER);
        $this->expectException(Error::class);
        $this->expectExceptionCode(403);
        User::update(self::USER, null, null, null, null, null, null, true);
    }

    public function testUpdateOtherUserRequiresAdmin()
    {
        $this->loginAs(self::USER);
        $this->expectException(Error::class);
        $this->expectExceptionCode(403);
        User::update(self::ADMIN, 'hacked@example.com');
    }

    public function testAdminUpdatesOtherUser()
    {
        $this->loginAs(self::ADMIN);
        $this->assertSame('Leg', User::update(self::LEGACY, null, 'Leg')['firstname']);
    }

    public function testUpdateUnknown()
    {
        $this->loginAs(self::ADMIN);
        $this->expectException(Error::class);
        User::update(99, 'x@example.com');
    }
}
