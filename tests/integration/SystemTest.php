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
        $schema = System::schema();
        $this->assertSame($result['version'], $schema['version']);
        $this->assertSame([], $schema['pending']);
        $this->assertIsBool($schema['weakJwtSecret']);
    }

    public function testHealth()
    {
        $health = System::health();
        $this->assertSame('ok', $health['status']);
        $this->assertSame('ok', $health['database']);
        $this->assertArrayHasKey('schema', $health);
        $this->assertSame(trim(file_get_contents(dirname(__DIR__, 2) . '/VERSION')), $health['version']);
    }

    public function testRotateJwtSecret()
    {
        $file = sys_get_temp_dir() . '/carbure-rotate-' . uniqid() . '.ini';
        file_put_contents($file, "[auth]\njwtsecret=secret\nsync_token=keep\n");
        System::$configFile = $file;
        try {
            $this->loginAs(self::ADMIN);
            $this->assertTrue(System::rotateJwtSecret());
            $ini = parse_ini_file($file);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ini['jwtsecret']);
            $this->assertSame('keep', $ini['sync_token']);

            $this->loginAs(self::USER);
            $this->expectException(Error::class);
            $this->expectExceptionCode(403);
            System::rotateJwtSecret();
        } finally {
            System::$configFile = null;
            @unlink($file);
        }
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
