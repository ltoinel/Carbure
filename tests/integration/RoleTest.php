<?php

use Carbure\Lib\ApiError;
use Carbure\Lib\ApiResolver;
use Carbure\Lib\ApiRoute;
use Carbure\Lib\Db;
use Carbure\Resources\Transaction;
use Carbure\Resources\User;

/**
 * Roles of the users (#539): administrator, member, and the read-only member (viewer),
 * refused by Webservice on every route that writes household data
 */
class RoleTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        User::forgetSchema();
        parent::tearDown();
    }

    /** Make the reference user a read-only member and sign in as him */
    private function asViewer()
    {
        Db::execute("UPDATE users SET role = 'viewer' WHERE id = ?", "i", self::USER);
        $this->loginAs(self::USER);
    }

    /**
     * Every route of the API, from the attributes of the resources.
     *
     * @return array<string, ApiRoute> "METHOD /path" => its attribute
     */
    private function routes()
    {
        $routes = [];
        foreach (glob(__DIR__ . '/../../src/resources/*.php') as $file) {
            $class = new ReflectionClass(ApiResolver::RESOURCES_NAMESPACE . basename($file, '.php'));
            foreach ($class->getMethods() as $method) {
                foreach ($method->getAttributes(ApiRoute::class) as $attribute) {
                    $route = $attribute->newInstance();
                    $routes[$route->method . ' ' . $route->path] = $route;
                }
            }
        }
        ksort($routes);
        return $routes;
    }

    public function testNoRouteThatWritesHouseholdDataIsOpenToAReadOnlyMember()
    {
        $this->asViewer();
        $refused = 0;
        foreach ($this->routes() as $name => $route) {
            // Public routes authenticate by themselves (login, OAuth, MCP: read-only tools)
            if ($route->public || !$route->writesHouseholdData()) {
                continue;
            }
            try {
                $this->callRoute($route->method, $route->path);
                $this->fail("$name accepted a read-only member");
            } catch (Error $e) {
                // Before the method is called: no parameter was even read
                $this->assertSame(403, $e->getCode(), $name);
                $this->assertContains($e->getMessage(), ['Forbidden - Read-only account', 'Forbidden - Administrator only'], $name);
                $refused++;
            }
        }
        // Every write of the household: transactions, budgets, tags, import, admin routes...
        $this->assertGreaterThan(50, $refused);
    }

    public function testAReadOnlyMemberIsToldSo()
    {
        $this->asViewer();
        try {
            $this->callRoute('PUT', '/transaction/pointed', ['id' => 1, 'pointed' => 1]);
            $this->fail('Accepted');
        } catch (ApiError $e) {
            $this->assertSame([403, 'read_only'], [$e->getCode(), $e->getErrorCode()]);
        }
    }

    public function testAReadOnlyMemberReadsTheHouseholdData()
    {
        $this->asViewer();
        foreach (['/user/me', '/bank', '/budget', '/tag', '/token'] as $path) {
            $this->assertNotSame('', $this->callRoute('GET', $path), $path);
        }
        $this->assertSame('viewer', json_decode($this->callRoute('GET', '/user/me'), true)['role']);
    }

    public function testAReadOnlyMemberStillManagesTheirOwnAccount()
    {
        $this->asViewer();
        $updated = json_decode($this->callRoute('PUT', '/user', ['id' => self::USER, 'firstname' => 'Viewer']), true);
        $this->assertSame(['Viewer', 'viewer', 0], [$updated['firstname'], $updated['role'], (int)$updated['is_admin']]);
        $token = json_decode($this->callRoute('POST', '/token', ['name' => 'agent']), true);
        $this->assertStringStartsWith('cbt_', $token['token']);
    }

    public function testAReadOnlyMemberCannotChangeTheirRole()
    {
        $this->asViewer();
        foreach ([['role' => 'member'], ['is_admin' => 'true']] as $change) {
            try {
                $this->callRoute('PUT', '/user', ['id' => self::USER] + $change);
                $this->fail('Accepted');
            } catch (Error $e) {
                $this->assertSame(403, $e->getCode());
            }
        }
    }

    public function testAReadOnlyMemberCannotStartASynchronization()
    {
        $this->asViewer();
        try {
            $this->callRoute('GET', '/bank/sync');
            $this->fail('Accepted');
        } catch (Error $e) {
            $this->assertSame([403, 'Forbidden - Read-only account'], [$e->getCode(), $e->getMessage()]);
        }
    }

    public function testAMemberWrites()
    {
        $this->loginAs(self::USER);
        $id = (int)Db::queryOne("SELECT id FROM bank_transaction LIMIT 1", "")['id'];
        $this->callRoute('PUT', '/transaction/pointed', ['id' => $id, 'pointed' => 1]);
        $this->assertEquals(1, Db::queryOne("SELECT pointed FROM bank_transaction WHERE id = ?", "i", $id)['pointed']);
    }

    public function testAnAdministratorSetsTheRoles()
    {
        $this->loginAs(self::ADMIN);
        $created = User::create('compta', 'password123', 'compta@example.org', role: 'viewer');
        $this->assertSame(['viewer', 0], [$created['role'], $created['is_admin']]);
        $this->assertSame('viewer', array_column(User::get(), 'role', 'username')['compta']);

        // Member, then administrator: is_admin follows the role
        $this->assertSame('member', User::update($created['id'], role: 'member')['role']);
        $updated = User::update($created['id'], role: 'admin');
        $this->assertSame(['admin', 1], [$updated['role'], (int)$updated['is_admin']]);
        // The role wins over the flag of the older clients
        $updated = User::update($created['id'], is_admin: true, role: 'viewer');
        $this->assertSame(['viewer', 0], [$updated['role'], (int)$updated['is_admin']]);
    }

    public function testTheFlagOfTheOlderClientsKeepsAReadOnlyMember()
    {
        // The portal of an older version sends is_admin with every change of a user
        Db::execute("UPDATE users SET role = 'viewer' WHERE id = ?", "i", self::USER);
        $this->loginAs(self::ADMIN);
        $this->assertSame('viewer', User::update(self::USER, firstname: 'Ugo', is_admin: false)['role']);
        $this->assertSame('admin', User::update(self::USER, is_admin: true)['role']);
        $this->assertSame('member', User::update(self::USER, is_admin: false)['role']);
        $this->assertSame('admin', User::me()['role']);
    }

    public function testAnUnknownRoleIsRefused()
    {
        $this->loginAs(self::ADMIN);
        $this->expectExceptionCode(400);
        User::update(self::USER, role: 'owner');
    }

    public function testTheLastAdministratorKeepsTheRole()
    {
        $this->loginAs(self::ADMIN);
        $this->expectExceptionCode(409);
        User::update(self::ADMIN, role: 'viewer');
    }

    public function testBeforeTheMigrationNobodyIsReadOnly()
    {
        Db::query("ALTER TABLE users DROP COLUMN role");
        User::forgetSchema();
        try {
            $this->loginAs(self::ADMIN);
            $this->assertSame(['admin', 'member', 'member'], array_column(User::get(), 'role'));
            // The flag still works, the read-only role waits for the migration
            $this->assertSame('admin', User::update(self::USER, is_admin: true)['role']);
            $this->assertSame('member', User::create('paul', 'password123', 'paul@example.org')['role']);
            try {
                User::update(self::USER, role: 'viewer');
                $this->fail('Accepted');
            } catch (Error $e) {
                $this->assertSame(409, $e->getCode());
            }
            $this->loginAs(self::LEGACY);
            User::requireWriter();
        } finally {
            Db::query("ALTER TABLE users ADD COLUMN role varchar(10) NOT NULL DEFAULT 'member' AFTER is_admin");
            User::forgetSchema();
        }
    }
}
