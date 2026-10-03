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

    public function testLogs()
    {
        $this->loginAs(self::ADMIN);
        $dir = Config::get('data_dir') . '/logs';
        @mkdir($dir, 0755, true);
        $file = 'carbure_unittest_' . date('Ymd') . '.log';
        file_put_contents("$dir/$file", "26:10:04 10:00:00 : INFO : aaa111 : Test.php->a : First\n"
            . "26:10:04 10:00:01 : ERROR : bbb222 : Test.php->b : Failure : Array\n(\n    [x] => 1\n)\n\n"
            . "26:10:04 10:00:02 : DEBUG : ccc333 : Test.php->c : Detail : eyJhbGc.eyJzdWIi.sig-1 cbt_abc123\n");
        try {
            $this->assertContains($file, array_column(System::logs(), 'name'));

            $all = System::logEntries($file);
            $this->assertSame(['DEBUG', 'ERROR', 'INFO'], array_column($all['entries'], 'level'));
            $this->assertSame('2026-10-04 10:00:02', $all['entries'][0]['time']);
            $this->assertStringContainsString('[x] => 1', $all['entries'][1]['message']);
            $this->assertSame('Detail : eyJ*** cbt_***', $all['entries'][0]['message']);

            $this->assertSame(['ERROR'], array_column(System::logEntries($file, 'ERROR')['entries'], 'level'));
            $this->assertSame(['aaa111'], array_column(System::logEntries($file, null, 'aaa111')['entries'], 'uid'));
            $this->assertCount(1, System::logEntries($file, null, null, 1)['entries']);

            foreach (['../conf/prod.ini', 'prod.ini', 'carbure_x.log'] as $name) {
                try {
                    System::logEntries($name);
                    $this->fail("Accepted $name");
                } catch (Error $e) {
                    $this->assertSame(400, $e->getCode());
                }
            }
        } finally {
            @unlink("$dir/$file");
        }
    }

    public function testAdministratorsOnly()
    {
        $this->loginAs(self::USER);
        foreach ([fn() => System::schema(), fn() => System::migrate(), fn() => System::logs(), fn() => System::logEntries('carbure_20260101.log')] as $call) {
            try {
                $call();
                $this->fail('Accepted');
            } catch (Error $e) {
                $this->assertSame(403, $e->getCode());
            }
        }
    }
}
