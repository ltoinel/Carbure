<?php

class SystemTest extends DatabaseTestCase
{
    public function testSchemaAndMigrate()
    {
        $this->loginAs(self::ADMIN);
        Db::query("DROP TABLE IF EXISTS schema_migrations");

        // The test database comes from carbure.sql: migrations are recognized and recorded
        $this->assertNotEmpty(System::schema()['pending']);
        $result = System::migrate();
        $this->assertNotEmpty($result['applied']);
        $this->assertSame(['version' => $result['version'], 'pending' => []], System::schema());
    }

    public function testAdministratorsOnly()
    {
        $this->loginAs(self::USER);
        foreach ([fn() => System::schema(), fn() => System::migrate()] as $call) {
            try {
                $call();
                $this->fail('Accepted');
            } catch (Error $e) {
                $this->assertSame(403, $e->getCode());
            }
        }
    }
}
