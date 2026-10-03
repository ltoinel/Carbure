<?php

use PHPUnit\Framework\TestCase;

/**
 * Runs tools/install.php against the test server (database, user, schema,
 * administrator and configuration file), then cleans up.
 */
class InstallTest extends TestCase
{
    private const ENV = 'install_test';
    private const DB = 'carbure_install_test';
    private const USER = 'carbure_install';

    private $root;

    protected function setUp(): void
    {
        if (!extension_loaded('mysqli') && !getenv('REQUIRE_DB')) {
            $this->markTestSkipped('mysqli extension not available');
        }
        $this->root = dirname(__DIR__, 2);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function admin()
    {
        return new mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root',
            getenv('DB_PASSWORD') ?: '', null, (int)(getenv('DB_PORT') ?: 3306));
    }

    private function cleanUp()
    {
        @unlink("$this->root/conf/" . self::ENV . ".ini");
        try {
            $admin = $this->admin();
            $admin->query("DROP DATABASE IF EXISTS " . self::DB);
            $admin->query("DROP USER IF EXISTS '" . self::USER . "'@'localhost'");
            $admin->query("DROP USER IF EXISTS '" . self::USER . "'@'%'");
        } catch (Throwable $e) {
            // No server: the test is skipped by DatabaseTestCase-like checks below
        }
    }

    private function install(array $extra = [])
    {
        $command = array_merge([PHP_BINARY, "$this->root/tools/install.php", '--no-interaction',
            '--env=' . self::ENV,
            '--db-host=' . (getenv('DB_HOST') ?: '127.0.0.1'),
            '--db-port=' . (getenv('DB_PORT') ?: 3306),
            '--db-name=' . self::DB,
            '--db-user=' . self::USER,
            '--db-root-user=' . (getenv('DB_USER') ?: 'root'),
            '--db-root-password=' . (getenv('DB_PASSWORD') ?: ''),
            '--admin-user=boss', '--admin-password=supersecret', '--admin-email=boss@example.com',
            '--woob-path=' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$this->root/tests/fixtures/fake-woob.php")], $extra);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $code = proc_close($process);
        return [$code, $output];
    }

    public function testInstall()
    {
        try {
            $this->admin();
        } catch (Throwable $e) {
            $this->markTestSkipped('No database server');
        }

        [$code, $output] = $this->install();
        $this->assertSame(0, $code, $output);

        // Configuration file with the database settings and random secrets
        $ini = parse_ini_file("$this->root/conf/" . self::ENV . ".ini");
        $this->assertSame(self::DB, $ini['db_name']);
        $this->assertSame(self::USER, $ini['db_username']);
        $this->assertStringContainsString('fake-woob.php', $ini['woob_path']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ini['jwtsecret']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $ini['sync_token']);
        $this->assertNotEmpty($ini['db_password']);

        // The application user can connect; schema, default category and admin exist
        $db = new mysqli($ini['db_hostname'], $ini['db_username'], $ini['db_password'], $ini['db_name'], (int)$ini['db_port']);
        $db->set_charset('utf8mb4');
        $this->assertSame('Non catégorisé', $db->query("SELECT name FROM bank_transaction_category WHERE id = 0")->fetch_row()[0]);
        $admin = $db->query("SELECT password, is_admin, language FROM users WHERE username = 'boss'")->fetch_assoc();
        $this->assertTrue(password_verify('supersecret', $admin['password']));
        $this->assertEquals(1, $admin['is_admin']);
        $this->assertSame('fr', $admin['language']);

        // The JWT secret can be renewed alone
        [$code, $output] = $this->install(['--rotate-jwt-secret']);
        $this->assertSame(0, $code, $output);
        $rotated = parse_ini_file("$this->root/conf/" . self::ENV . ".ini");
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $rotated['jwtsecret']);
        $this->assertNotSame($ini['jwtsecret'], $rotated['jwtsecret']);
        $this->assertSame($ini['db_password'], $rotated['db_password']);
        $this->assertSame($ini['sync_token'], $rotated['sync_token']);

        // A second run never overwrites the configuration without --force
        [$code, $output] = $this->install();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('already exists', $output);

        // With --force: the schema and the administrator are kept
        [$code, $output] = $this->install(['--force']);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('schema already present', $output);
        $this->assertStringContainsString('an administrator already exists', $output);
    }

    public function testBankAccountsProvisioning()
    {
        try {
            $this->admin();
        } catch (Throwable $e) {
            $this->markTestSkipped('No database server');
        }

        // Installation with a woob backend already configured: its accounts are followed
        [$code, $output] = $this->install(['--bank-backend=mybank', '--bank-accounts=2']);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('1) Compte chèques (00012345678@mybank)', $output);
        $this->assertStringContainsString('account Livret A followed by boss', $output);

        $ini = parse_ini_file("$this->root/conf/" . self::ENV . ".ini");
        $db = new mysqli($ini['db_hostname'], $ini['db_username'], $ini['db_password'], $ini['db_name'], (int)$ini['db_port']);
        $accounts = $db->query("SELECT account_number, bank_name FROM bank_account")->fetch_all(MYSQLI_ASSOC);
        $this->assertSame([['account_number' => '00087654321', 'bank_name' => 'mybank']], $accounts);

        // Later, --add-bank on the existing instance: all the accounts, no duplicate
        $add = function (array $extra) {
            $command = array_merge([PHP_BINARY, "$this->root/tools/install.php", '--no-interaction', '--add-bank', '--env=' . self::ENV], $extra);
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            return [proc_close($process), $output];
        };
        [$code, $output] = $add(['--bank-backend=mybank', '--bank-accounts=all']);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('already followed by boss', $output);
        $this->assertSame(2, (int)$db->query("SELECT COUNT(*) FROM bank_account")->fetch_row()[0]);

        // A backend that woob cannot load: reported, nothing added
        [$code, $output] = $add(['--bank-backend=none']);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('no account returned by woob for "none": Error(none): Unable to load module', $output);

        // Unknown owner
        [$code, $output] = $add(['--bank-backend=mybank', '--bank-owner=nobody']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Carbure user not found: nobody', $output);
    }

    public function testShortAdminPassword()
    {
        try {
            $this->admin();
        } catch (Throwable $e) {
            $this->markTestSkipped('No database server');
        }

        [$code, $output] = $this->install(['--admin-password=short']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('at least 8 characters', $output);
    }
}
