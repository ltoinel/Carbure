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
        @unlink(sys_get_temp_dir() . '/carbure-fake-woob-other-keyring');
    }

    /** The fake woob trusts no keyring */
    private function forget(): void
    {
        array_map('unlink', glob(WoobRepository::$keyringDir . '/*') ?: []);
        @rmdir((string)WoobRepository::$keyringDir);
    }

    /**
     * Update woob, returning the result and the lines written by woob.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function update(?string $fingerprint): array
    {
        $lines = [];
        $result = WoobRepository::update($fingerprint, function (string $line) use (&$lines) {
            $lines[] = $line;
        });
        return [$result, $lines];
    }

    public function testMatchesOnlyTheConfirmedFingerprint()
    {
        $keyring = str_repeat('ab', 20);
        $this->assertTrue(WoobRepository::matches($keyring, $keyring));
        $this->assertTrue(WoobRepository::matches($keyring, ' ' . strtoupper($keyring)));
        $this->assertFalse(WoobRepository::matches($keyring, str_repeat('cd', 20)));
        $this->assertFalse(WoobRepository::matches($keyring, null));
        $this->assertFalse(WoobRepository::matches($keyring, ''));
    }

    public function testStatusOfARepositoryNotTrusted()
    {
        $status = WoobRepository::status();
        $this->assertFalse($status['trusted']);
        // The fingerprint to confirm
        $this->assertSame($this->fingerprint, $status['fingerprint']);
        $this->assertNull($status['error']);
    }

    public function testRepositoryNotReachable()
    {
        $repository = Config::get('woob_repository');
        Config::set('woob_repository', sys_get_temp_dir() . '/carbure-no-repository');
        try {
            $status = WoobRepository::status();
            [$result, $lines] = $this->update($this->fingerprint);
        } finally {
            Config::set('woob_repository', $repository);
        }
        $this->assertFalse($status['trusted']);
        $this->assertNull($status['fingerprint']);
        $this->assertStringContainsString('not downloaded', $status['error']);
        // Nothing to compare with: woob is not started
        $this->assertFalse($result['updated']);
        $this->assertStringContainsString('not downloaded', $result['message']);
        $this->assertSame([], $lines);
        $this->assertFalse(WoobRepository::isTrusted());
    }

    public function testWoobNotStartedWithoutTheConfirmedFingerprint()
    {
        // woob would trust any keyring by itself (no terminal): it is not even started
        foreach ([null, str_repeat('0', 40)] as $confirmed) {
            [$result, $lines] = $this->update($confirmed);
            $this->assertFalse($result['updated']);
            $this->assertTrue($result['refused']);
            $this->assertSame($this->fingerprint, $result['asked']);
            $this->assertSame([], $lines);
            $this->assertFalse(WoobRepository::isTrusted());
        }
    }

    public function testTheConfirmedKeyringIsTrustedThenNoMoreConfirmationNeeded()
    {
        [$result, $lines] = $this->update(strtoupper($this->fingerprint));
        $this->assertTrue($result['updated']);
        $this->assertFalse($result['refused']);
        $this->assertContains('Are you sure you want to continue? (Y/n): *** ASSUMING YES ***', $lines);
        $this->assertSame('100% All modules are up-to-date.', $result['message']);
        $this->assertSame(['repository' => WoobRepository::repository(), 'trusted' => true, 'fingerprint' => $this->fingerprint, 'error' => null],
            WoobRepository::status());

        // Trusted: woob checks the next keyrings itself
        [$result] = $this->update(null);
        $this->assertTrue($result['updated']);
        $this->assertNull($result['asked']);
    }

    public function testAKeyringStoredByWoobOtherThanTheConfirmedOneIsRemoved()
    {
        // The repository served another keyring to woob than to Carbure
        touch(sys_get_temp_dir() . '/carbure-fake-woob-other-keyring');
        [$result] = $this->update($this->fingerprint);
        $this->assertFalse($result['updated']);
        $this->assertStringContainsString('is not the confirmed one', $result['message']);
        $this->assertFalse(WoobRepository::isTrusted());
        $this->assertSame([], glob(WoobRepository::$keyringDir . '/*') ?: []);
    }
}
