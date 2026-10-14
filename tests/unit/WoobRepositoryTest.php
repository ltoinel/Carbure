<?php

use PHPUnit\Framework\TestCase;

class WoobRepositoryTest extends TestCase
{
    /** Fingerprint of the keyring of the fake repository (tests/fixtures/woob-repository) */
    private string $fingerprint;

    protected function setUp(): void
    {
        $this->fingerprint = sha1_file(dirname(__DIR__) . '/fixtures/woob-repository/trusted.gpg');
        $this->forget();
    }

    protected function tearDown(): void
    {
        $this->forget();
    }

    /** The fake woob trusts no keyring */
    private function forget(): void
    {
        array_map('unlink', glob(WoobRepository::$keyringDir . '/*') ?: []);
        @rmdir((string)WoobRepository::$keyringDir);
    }

    public function testAnswersYesOnlyToTheConfirmedFingerprint()
    {
        $asked = str_repeat('ab', 20);
        $this->assertSame('y', WoobRepository::answer($asked, $asked));
        $this->assertSame('y', WoobRepository::answer($asked, strtoupper($asked)));
        $this->assertSame('n', WoobRepository::answer($asked, str_repeat('cd', 20)));
        $this->assertSame('n', WoobRepository::answer($asked, null));
        // Never an empty answer: woob takes it for a yes
        $this->assertSame('n', WoobRepository::answer($asked, ''));
    }

    public function testReadsTheQuestionOfWoob()
    {
        $output = "The repository https://updates.woob.tech/3/main/ isn't trusted yet.\n"
            . "Fingerprint of keyring is B5FC136C2D500B8483696A0EE722865842A4CC45\n"
            . "Are you sure you want to continue? (Y/n): ";
        $this->assertSame('b5fc136c2d500b8483696a0ee722865842a4cc45', WoobRepository::askedFingerprint($output));
        $this->assertTrue(WoobRepository::waitsForAnswer('Are you sure you want to continue? (Y/n): '));
        $this->assertFalse(WoobRepository::waitsForAnswer('Updating repositories'));
        $this->assertNull(WoobRepository::askedFingerprint('All modules are up-to-date.'));
    }

    public function testStatusOfARepositoryNotTrusted()
    {
        $status = WoobRepository::status();
        $this->assertFalse($status['trusted']);
        // The fingerprint woob will ask to trust, to compare
        $this->assertSame($this->fingerprint, $status['fingerprint']);
        $this->assertNull($status['error']);
    }

    public function testRepositoryNotReachable()
    {
        $repository = Config::get('woob_repository');
        Config::set('woob_repository', sys_get_temp_dir() . '/carbure-no-repository');
        try {
            $status = WoobRepository::status();
        } finally {
            Config::set('woob_repository', $repository);
        }
        $this->assertFalse($status['trusted']);
        $this->assertNull($status['fingerprint']);
        $this->assertStringContainsString('not downloaded', $status['error']);
    }

    public function testUpdateRefusesAKeyringNotConfirmed()
    {
        foreach ([null, str_repeat('0', 40)] as $confirmed) {
            $lines = [];
            $result = WoobRepository::update($confirmed, function (string $line) use (&$lines) {
                $lines[] = $line;
            });
            $this->assertFalse($result['updated']);
            $this->assertTrue($result['refused']);
            $this->assertSame($this->fingerprint, $result['asked']);
            $this->assertContains("Repository not trusted: $this->fingerprint", $lines);
            $this->assertFalse(WoobRepository::status()['trusted']);
        }
    }

    public function testUpdateTrustsTheConfirmedKeyringThenAsksNoMore()
    {
        $lines = [];
        $result = WoobRepository::update($this->fingerprint, function (string $line) use (&$lines) {
            $lines[] = $line;
        });
        $this->assertTrue($result['updated']);
        $this->assertFalse($result['refused']);
        $this->assertContains("Repository trusted: $this->fingerprint", $lines);
        $this->assertContains('100% All modules are up-to-date.', $lines);
        $this->assertSame('100% All modules are up-to-date.', $result['message']);
        $this->assertSame(['repository' => WoobRepository::repository(), 'trusted' => true, 'fingerprint' => $this->fingerprint, 'error' => null],
            WoobRepository::status());

        // Trusted: no question, no fingerprint needed
        $result = WoobRepository::update(null, fn(string $line) => null);
        $this->assertTrue($result['updated']);
        $this->assertNull($result['asked']);
    }
}
