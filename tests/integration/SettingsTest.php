<?php

/**
 * Configuration file in the portal and request of the synchronization
 */
class SettingsTest extends DatabaseTestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir() . '/carbure-settings-' . uniqid() . '.ini';
        file_put_contents($this->file, <<<'INI'
            [global]
            log_level=warning
            savings_category=Epargne
            [database]
            db_hostname=localhost
            db_password=s3cret
            [cleansing]
            regex_label["/FACTURE CARTE DU \d{6}\s*/"]="CB "
            [auth]
            jwtsecret=0123456789abcdef
            sync_token=tok
            [woob]
            woob_path=/bin/woob
            woob_transactions=100
            woob_logging=error
            woob_debug=false
            INI);
        System::$configFile = $this->file;
    }

    protected function tearDown(): void
    {
        System::$configFile = null;
        @unlink($this->file);
        @unlink($this->file . '.bak');
        parent::tearDown();
    }

    /**
     * A setting of the answer of GET /system/config
     */
    private function setting($config, $key)
    {
        foreach ($config['sections'] as $section) {
            foreach ($section['settings'] as $setting) {
                if ($setting['key'] === $key) {
                    return $setting + ['section' => $section['name']];
                }
            }
        }
        return null;
    }

    public function testGetMasksTheSecrets()
    {
        $this->loginAs(self::ADMIN);
        $config = Settings::get();

        $this->assertTrue($config['writable']);
        $this->assertSame(['global', 'database', 'cleansing', 'auth', 'woob', 'apns'], array_column($config['sections'], 'name'));
        $password = $this->setting($config, 'db_password');
        $this->assertTrue($password['secret']);
        $this->assertFalse($password['editable']);
        $this->assertStringNotContainsString('s3cret', json_encode($config));
        $this->assertStringNotContainsString('0123456789abcdef', json_encode($config));

        // woob_path runs a command: read-only
        $this->assertSame('/bin/woob', $this->setting($config, 'woob_path')['value']);
        $this->assertFalse($this->setting($config, 'woob_path')['editable']);
        $this->assertSame(['debug', 'info', 'warning', 'error'], $this->setting($config, 'log_level')['options']);
        $this->assertFalse($this->setting($config, 'woob_debug')['value']);
        $this->assertSame('list', $this->setting($config, 'regex_label')['kind']);
        // Editable but missing from the file
        $this->assertFalse($this->setting($config, 'public_url')['set']);
        $this->assertSame('apns', $this->setting($config, 'apns_environment')['section']);
    }

    public function testUpdate()
    {
        $this->loginAs(self::ADMIN);
        $config = Settings::update(['log_level' => 'debug', 'savings_category' => 'Économies', 'woob_debug' => true,
                                    'woob_transactions' => '250', 'public_url' => 'https://carbure.example.fr/']);

        $ini = parse_ini_file($this->file);
        $this->assertSame('debug', $ini['log_level']);
        $this->assertSame('Économies', $ini['savings_category']);
        $this->assertSame('1', $ini['woob_debug']);
        $this->assertSame('250', $ini['woob_transactions']);
        $this->assertSame('https://carbure.example.fr', $ini['public_url']);
        // The other settings are kept, the previous file too
        $this->assertSame('s3cret', $ini['db_password']);
        $this->assertStringContainsString('log_level=warning', file_get_contents($this->file . '.bak'));
        $this->assertTrue($this->setting($config, 'woob_debug')['value']);
    }

    /**
     * @return array Settings that cannot be changed, and values that are refused
     */
    public static function refusedValues(): array
    {
        return [
            'command' => ['woob_path', '/bin/sh -c id'],
            'database' => ['db_hostname', 'evil'],
            'secret' => ['jwtsecret', 'x'],
            'uuid' => ['regex_label', 'x'],
            'unknown' => ['whatever', 'x'],
            'injection in woob command' => ['woob_logging', 'error; id'],
            'not a number' => ['woob_transactions', '10; id'],
            'too many' => ['woob_transactions', '999999'],
            'not a level' => ['log_level', 'verbose'],
            'newline' => ['savings_category', "Epargne\nwoob_path=/bin/sh"],
            'not a url' => ['public_url', 'javascript:alert(1)'],
            'array' => ['log_level', ['debug']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedValues')]
    public function testRefusedValues($key, $value)
    {
        $this->loginAs(self::ADMIN);
        $before = file_get_contents($this->file);
        try {
            Settings::update([$key => $value]);
            $this->fail("$key accepted");
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }
        $this->assertSame($before, file_get_contents($this->file));
    }

    public function testNothingIsWrittenWhenOneValueIsRefused()
    {
        $this->loginAs(self::ADMIN);
        $before = file_get_contents($this->file);
        try {
            Settings::update(['log_level' => 'debug', 'woob_path' => '/tmp/x']);
        } catch (Error $e) {
        }
        $this->assertSame($before, file_get_contents($this->file));
    }

    public function testSyncCommand()
    {
        $this->loginAs(self::ADMIN);
        $sync = Settings::sync();
        $this->assertStringEndsWith('/api/bank/sync', $sync['url']);
        $this->assertTrue($sync['masked']);
        $this->assertNotSame('tok', $sync['token']);
        $this->assertSame(['111@bnp', 'fail@bank'], $sync['accounts']);
        $this->assertSame('tok', Settings::sync(true)['token']);

        $token = Settings::renewSyncToken()['token'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $token);
        $this->assertSame($token, parse_ini_file($this->file)['sync_token']);
        $this->assertSame($token, Settings::sync('true')['token']);
    }

    public function testSyncWithoutToken()
    {
        file_put_contents($this->file, "[auth]\nsync_token=\n");
        $this->loginAs(self::ADMIN);
        $sync = Settings::sync(true);
        $this->assertNull($sync['token']);
        $this->assertFalse($sync['masked']);
    }

    public function testAdministratorsOnly()
    {
        $this->loginAs(self::USER);
        foreach ([fn() => Settings::get(), fn() => Settings::update(['log_level' => 'debug']), fn() => Settings::sync(true),
                  fn() => Settings::renewSyncToken()] as $call) {
            try {
                $call();
                $this->fail('A user is not an administrator');
            } catch (Error $e) {
                $this->assertSame(403, $e->getCode());
            }
        }
    }
}
