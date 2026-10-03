<?php

use PHPUnit\Framework\TestCase;

/**
 * Installation wizard of the portal (Setup) against the test server, then the
 * maintenance tool (tools/carbure.php) on the installed instance.
 */
class SetupTest extends TestCase
{
    private const ENV = 'setup_test';
    private const DB = 'carbure_setup_test';

    private string $root;
    private string $configFile;
    private string $codeFile;
    private Setup $setup;

    protected function setUp(): void
    {
        if (!extension_loaded('mysqli') && !getenv('REQUIRE_DB')) {
            $this->markTestSkipped('mysqli extension not available');
        }
        $this->root = dirname(__DIR__, 2);
        $this->configFile = "$this->root/conf/" . self::ENV . ".ini";
        $this->codeFile = sys_get_temp_dir() . '/carbure-setup-' . uniqid() . '.code';
        try {
            $this->server()->query("DROP DATABASE IF EXISTS " . self::DB);
            $this->server()->query("CREATE DATABASE " . self::DB . " CHARACTER SET utf8mb4");
        } catch (Throwable $e) {
            if (getenv('REQUIRE_DB')) {
                throw $e;
            }
            $this->markTestSkipped('No database server');
        }
        @unlink($this->configFile);
        putenv('CARBURE_WOOB_PATH=' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$this->root/tests/fixtures/fake-woob.php"));
        $this->setup = new Setup($this->root, $this->configFile, $this->codeFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->configFile);
        @unlink($this->codeFile);
        putenv('CARBURE_WOOB_PATH');
        try {
            $this->server()->query("DROP DATABASE IF EXISTS " . self::DB);
        } catch (Throwable $e) {
            // No server
        }
    }

    private function server()
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        return new mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root',
            getenv('DB_PASSWORD') ?: '', null, (int)(getenv('DB_PORT') ?: 3306));
    }

    private function fields(array $extra = [])
    {
        return $extra + [
            'code' => '',
            'db_host' => getenv('DB_HOST') ?: '127.0.0.1',
            'db_port' => (int)(getenv('DB_PORT') ?: 3306),
            'db_name' => self::DB,
            'db_user' => getenv('DB_USER') ?: 'root',
            'db_password' => getenv('DB_PASSWORD') ?: '',
            'admin_user' => 'boss',
            'admin_password' => 'supersecret',
            'admin_email' => 'boss@example.com',
            'language' => 'en',
        ];
    }

    private function db()
    {
        $ini = parse_ini_file($this->configFile);
        $db = new mysqli($ini['db_hostname'], $ini['db_username'], $ini['db_password'], $ini['db_name'], (int)$ini['db_port']);
        $db->set_charset('utf8mb4');
        return $db;
    }

    private function tool(array $arguments)
    {
        $process = proc_open(array_merge([PHP_BINARY, "$this->root/tools/carbure.php"], $arguments, ['--env=' . self::ENV, '--no-interaction']),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($process), $output];
    }

    public function testNoCodeFromTheLocalNetwork()
    {
        foreach (['127.0.0.1', '192.168.1.20', '10.0.0.5', '::1'] as $address) {
            $setup = new Setup($this->root, $this->configFile, $this->codeFile, $address);
            [$status, $body] = $setup->handle('GET', '/setup', []);
            $this->assertFalse($body['codeRequired'], $address);
            [$status] = $setup->handle('POST', '/setup/database', $this->fields(['code' => '']));
            $this->assertSame(200, $status, $address);
        }
        $this->assertFileDoesNotExist($this->codeFile);
    }

    public function testCodeFromInternet()
    {
        $setup = new Setup($this->root, $this->configFile, $this->codeFile, '8.8.8.8');
        [$status, $body] = $setup->handle('GET', '/setup', []);
        $this->assertSame(200, $status);
        $this->assertTrue($body['setup']);
        $this->assertTrue($body['codeRequired']);
        $this->assertArrayHasKey('db_host', $body['defaults']);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{12}$/', trim(file_get_contents($this->codeFile)));

        // Wrong code, then the right one (case and spaces ignored)
        [$status] = $setup->handle('POST', '/setup/database', $this->fields(['code' => 'WRONG']));
        $this->assertSame(403, $status);
        [$status] = $setup->handle('POST', '/setup/database', $this->fields(['code' => ' ' . strtolower($setup->code())]));
        $this->assertSame(200, $status);

        // Once the file exists, it is required from the local network too
        [$status] = $this->setup->handle('POST', '/setup/database', $this->fields(['code' => 'WRONG']));
        $this->assertSame(403, $status);

        // Any other route: not installed
        [$status, $body] = $this->setup->handle('GET', '/transaction', []);
        $this->assertSame(503, $status);
        $this->assertTrue($body['setup']);
    }

    public function testInstallOnAnEmptyDatabase()
    {
        [$status, $body] = $this->setup->handle('POST', '/setup/database', $this->fields());
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('none', $body['state']);

        // Bad password of the database
        [$status, $body] = $this->setup->handle('POST', '/setup/database', $this->fields(['db_password' => 'wrong-' . uniqid()]));
        $this->assertSame(400, $status);
        $this->assertStringStartsWith('Database:', $body['error']);

        // Short administrator password
        [$status] = $this->setup->handle('POST', '/setup/install', $this->fields(['admin_password' => 'short']));
        $this->assertSame(400, $status);
        $this->assertFileDoesNotExist($this->configFile);

        [$status, $body] = $this->setup->handle('POST', '/setup/install', $this->fields());
        $this->assertSame(200, $status, json_encode($body));
        $this->assertTrue($body['done']);
        $this->assertSame('boss', $body['username']);
        $this->assertFileDoesNotExist($this->codeFile);

        // Configuration with the database settings and random secrets
        $ini = parse_ini_file($this->configFile);
        $this->assertSame(self::DB, $ini['db_name']);
        $this->assertStringContainsString('fake-woob.php', $ini['woob_path']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ini['jwtsecret']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $ini['sync_token']);

        // Schema with its version, default category and administrator
        $db = $this->db();
        $this->assertSame('Non catégorisé', $db->query("SELECT name FROM bank_transaction_category WHERE id = 0")->fetch_row()[0]);
        $admin = $db->query("SELECT password, is_admin, language FROM users WHERE username = 'boss'")->fetch_assoc();
        $this->assertTrue(password_verify('supersecret', $admin['password']));
        $this->assertEquals(1, $admin['is_admin']);
        $this->assertSame('en', $admin['language']);
        $this->assertSame($body['version'], (new Migrator($db))->version());
        $this->assertSame([], (new Migrator($db))->pending());

        // Maintenance tool: new jwtsecret only
        [$code, $output] = $this->tool(['rotate-jwt-secret']);
        $this->assertSame(0, $code, $output);
        $rotated = parse_ini_file($this->configFile);
        $this->assertNotSame($ini['jwtsecret'], $rotated['jwtsecret']);
        $this->assertSame($ini['sync_token'], $rotated['sync_token']);

        // Maintenance tool: follow the accounts of a woob backend, no duplicate
        [$code, $output] = $this->tool(['add-bank', '--bank-backend=mybank', '--bank-accounts=2']);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('1) Compte chèques (00012345678@mybank)', $output);
        $this->assertStringContainsString('account Livret A followed by boss', $output);
        [$code, $output] = $this->tool(['add-bank', '--bank-backend=mybank', '--bank-accounts=all']);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('already followed by boss', $output);
        $this->assertSame(2, (int)$db->query("SELECT COUNT(*) FROM bank_account")->fetch_row()[0]);

        [$code, $output] = $this->tool(['add-bank', '--bank-backend=none']);
        $this->assertStringContainsString('no account returned by woob for "none"', $output);
        [$code, $output] = $this->tool(['add-bank', '--bank-backend=mybank', '--bank-owner=nobody']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Carbure user not found: nobody', $output);
        [$code, $output] = $this->tool(['unknown']);
        $this->assertSame(1, $code);
    }

    public function testExistingDatabaseToMigrate()
    {
        // A database installed before the last migration, with an administrator
        $db = Installer::connect(getenv('DB_HOST') ?: '127.0.0.1', (int)(getenv('DB_PORT') ?: 3306), self::DB,
            getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '');
        Installer::installSchema($db, $this->root);
        Installer::createAdmin($db, 'boss', 'supersecret');
        $db->query("DROP TABLE api_tokens");
        $db->query("DELETE FROM schema_migrations WHERE version = '2026-10-07_api_tokens'");

        [$status, $body] = $this->setup->handle('POST', '/setup/database', $this->fields());
        $this->assertSame('outdated', $body['state']);
        $this->assertSame(['2026-10-07_api_tokens'], $body['pending']);
        $this->assertTrue($body['hasAdmin']);

        // The migration needs the confirmation of a backup
        [$status] = $this->setup->handle('POST', '/setup/install', $this->fields(['admin_password' => '']));
        $this->assertSame(409, $status);

        [$status, $body] = $this->setup->handle('POST', '/setup/install', $this->fields(['admin_password' => '', 'backup_confirmed' => true]));
        $this->assertSame(200, $status, json_encode($body));
        $this->assertNull($body['username']);
        $this->assertSame(1, $db->query("SHOW TABLES LIKE 'api_tokens'")->num_rows);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM users")->fetch_row()[0]);
    }

    public function testToolWithoutConfiguration()
    {
        [$code, $output] = $this->tool(['add-bank']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('install Carbure first, from the portal', $output);
    }
}
