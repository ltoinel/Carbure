<?php

use Carbure\Lib\Config;
use Carbure\Lib\Secret;
use Carbure\Lib\Woob;
use PHPUnit\Framework\TestCase;

/**
 * Encryption at rest of the bank credentials kept by woob (#532)
 */
class SecretTest extends TestCase
{
    private const KEY = 'a-key-kept-outside-the-data-volume-1';
    private const OTHER = 'another-key-given-by-a-docker-secret-2';

    protected function setUp(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            $this->markTestSkipped('libsodium not available');
        }
    }

    protected function tearDown(): void
    {
        foreach ([Secret::KEY, Secret::OLD_KEY, Secret::KEY . '_FILE'] as $name) {
            putenv($name);
        }
        @unlink(Woob::backendsFile());
        @unlink(carbure_test_tmp('carbure-secret-key'));
    }

    /** Configure the key (and the previous one) as the environment of the container does */
    private function keys(?string $key, ?string $old = null)
    {
        putenv($key === null ? Secret::KEY : Secret::KEY . "=$key");
        putenv($old === null ? Secret::OLD_KEY : Secret::OLD_KEY . "=$old");
    }

    /** Run the command woob finds between the backquotes of a setting, as woob does */
    private function runSetting(string $line)
    {
        $this->assertMatchesRegularExpression('/^\w+ = `.+`$/', $line);
        $command = substr($line, (int)strpos($line, '`') + 1, -1);
        exec("$command 2>/dev/null", $output, $code);
        return [$code, $output[0] ?? null];
    }

    /** Lines of the file of the banks */
    private function lines()
    {
        return explode("\n", trim((string)file_get_contents(Woob::backendsFile())));
    }

    public function testWithoutAKeyNothingIsEncrypted()
    {
        $this->keys(null);
        $this->assertFalse(Secret::available());
        $this->assertNull(Secret::decrypt('v1:AAAA'));
        $this->expectExceptionMessage('CARBURE_SECRET_KEY is not set');
        Secret::encrypt('secret');
    }

    public function testAValueIsDecryptedWithItsKeyOnly()
    {
        $this->keys(self::KEY);
        $encrypted = Secret::encrypt('p@ss;%#=[x] é');
        $this->assertStringStartsWith('v1:', $encrypted);
        $this->assertStringNotContainsString('p@ss', $encrypted);
        // A new nonce each time: two encryptions of a value differ
        $this->assertNotSame($encrypted, Secret::encrypt('p@ss;%#=[x] é'));
        $this->assertSame('p@ss;%#=[x] é', Secret::decrypt($encrypted));

        $this->keys(self::OTHER);
        $this->assertNull(Secret::decrypt($encrypted));
        // Truncated, altered or foreign values: never a partial result
        $this->keys(self::KEY);
        foreach ([substr($encrypted, 0, -4), 'v1:not base64', 'v2:' . substr($encrypted, 3), 'clear', ''] as $value) {
            $this->assertNull(Secret::decrypt($value), $value);
        }
    }

    public function testThePreviousKeyStillDecrypts()
    {
        $this->keys(self::KEY);
        $encrypted = Secret::encrypt('secret');
        $this->keys(self::OTHER, self::KEY);
        $this->assertSame('secret', Secret::decrypt($encrypted));
        $this->assertFalse(Secret::isCurrent($encrypted));
        $this->assertTrue(Secret::isCurrent(Secret::encrypt('secret')));
    }

    public function testAShortKeyIsRefused()
    {
        $this->keys('too-short');
        $this->expectExceptionMessage('CARBURE_SECRET_KEY is too short: at least 32 characters');
        Secret::available();
    }

    public function testTheKeyOfASecretFile()
    {
        $this->keys(self::KEY);
        $encrypted = Secret::encrypt('secret');
        $this->keys(null);
        file_put_contents(carbure_test_tmp('carbure-secret-key'), self::KEY . "\n");
        putenv(Secret::KEY . '_FILE=' . carbure_test_tmp('carbure-secret-key'));
        $this->assertTrue(Secret::available());
        $this->assertSame('secret', Secret::decrypt($encrypted));
    }

    public function testANewBankIsWrittenEncrypted()
    {
        $this->keys(self::KEY);
        Woob::addBackend('bnp', 'mybnp', ['login' => '12345678', 'Password' => 'p@ss;%#=[x]', 'website' => '']);

        $content = (string)file_get_contents(Woob::backendsFile());
        $this->assertStringNotContainsString('12345678', $content);
        $this->assertStringNotContainsString('p@ss', $content);
        [$section, $module, $login, $password, $website] = $this->lines();
        $this->assertSame(['[mybnp]', '_module = bnp', 'website ='], [$section, $module, $website]);
        // Only the path of the tool of Carbure and base64 between the backquotes
        $tool = escapeshellarg(Config::get('install_dir') . '/tools/carbure-secret.php');
        $this->assertMatchesRegularExpression('/^login = `' . preg_quote($tool, '/') . ' v1:[A-Za-z0-9+\/=]+`$/', $login);
        // woob runs the command with the key in its environment, and reads its first line
        $this->assertSame([0, '12345678'], $this->runSetting($login));
        $this->assertSame([0, 'p@ss;%#=[x]'], $this->runSetting($password));
        $this->assertSame(['key' => true, 'settings' => 2, 'encrypted' => 2, 'clear' => 0, 'undecryptable' => 0], Woob::credentialsStatus());

        // Without the key (a copy of the volume alone): nothing comes out
        $this->keys(null);
        $this->assertSame([1, null], $this->runSetting($password));
        $this->keys(self::OTHER);
        $this->assertSame([1, null], $this->runSetting($password));
    }

    public function testTheBanksAlreadyConnectedAreEncryptedBeforeWoobRuns()
    {
        // Left by a version without encryption, with a command written by hand
        $this->keys(null);
        file_put_contents(Woob::backendsFile(), "[cic_pro]\n_module = cic\nlogin = jdoe\npassword = `pass show cic`\n\n[bnp]\n_module = bnp\npassword = hunter2\n");
        Woob::path();
        $this->assertStringContainsString("login = jdoe\n", (string)file_get_contents(Woob::backendsFile()));
        $this->assertSame(['key' => false, 'settings' => 3, 'encrypted' => 0, 'clear' => 2, 'undecryptable' => 0], Woob::credentialsStatus());

        $this->keys(self::KEY);
        Woob::path();
        $lines = $this->lines();
        $this->assertSame(['[cic_pro]', '_module = cic'], array_slice($lines, 0, 2));
        $this->assertSame([0, 'jdoe'], $this->runSetting($lines[2]));
        $this->assertSame('password = `pass show cic`', $lines[3]);
        $this->assertSame(['', '[bnp]', '_module = bnp'], array_slice($lines, 4, 3));
        $this->assertSame([0, 'hunter2'], $this->runSetting($lines[7]));
        $this->assertSame(0, Woob::credentialsStatus()['clear']);

        // Nothing left to do: the file is not rewritten at each run of woob
        $before = file_get_contents(Woob::backendsFile());
        Woob::path();
        $this->assertSame($before, file_get_contents(Woob::backendsFile()));
    }

    public function testTheKeyIsReplaced()
    {
        $this->keys(self::KEY);
        Woob::addBackend('bnp', 'mybnp', ['password' => 'hunter2']);
        $old = $this->lines()[2];

        // The new key with the previous one: everything is encrypted again at the next run
        $this->keys(self::OTHER, self::KEY);
        Woob::path();
        $new = $this->lines()[2];
        $this->assertNotSame($old, $new);

        // The previous key can then be removed
        $this->keys(self::OTHER);
        $this->assertSame([0, 'hunter2'], $this->runSetting($new));
        $this->assertSame([1, null], $this->runSetting($old));
        $this->assertSame(0, Woob::credentialsStatus()['undecryptable']);
    }

    public function testALostKey()
    {
        $this->keys(self::KEY);
        Woob::addBackend('bnp', 'mybnp', ['password' => 'hunter2']);
        $before = file_get_contents(Woob::backendsFile());

        // Another key: the credentials are kept as they are, and reported
        $this->keys(self::OTHER);
        Woob::path();
        $this->assertSame($before, file_get_contents(Woob::backendsFile()));
        $this->assertSame(['key' => true, 'settings' => 1, 'encrypted' => 1, 'clear' => 0, 'undecryptable' => 1], Woob::credentialsStatus());

        // No key at all: woob is not even started
        $this->keys(null);
        $this->expectExceptionMessage('The bank credentials are encrypted and CARBURE_SECRET_KEY is not set');
        Woob::path();
    }

    public function testTheErrorOfWoobOnACredentialIsExplained()
    {
        $fail = new ReflectionMethod(Woob::class, 'failOnDecryption');
        $fail->invoke(null, "Error(bnp): 403 Client Error");
        $this->expectExceptionMessage('The bank credentials cannot be decrypted: CARBURE_SECRET_KEY is missing or is not the key');
        $fail->invoke(null, "ValueError: The call to the external tool failed: Command '...' returned non-zero exit status 1.");
    }

    public function testABackquoteTypedByAUserIsStillRefused()
    {
        $this->keys(self::KEY);
        $this->expectExceptionMessage('Invalid setting password');
        Woob::addBackend('bnp', 'mybnp', ['password' => '`cat /etc/passwd`']);
    }
}
