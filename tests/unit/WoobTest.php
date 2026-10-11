<?php

use PHPUnit\Framework\TestCase;

class WoobTest extends TestCase
{
    public function testHistoryIsParsedLineByLine()
    {
        $data = Woob::getBankData('history', '123@bnp');

        // Two transactions in a JSON array + one single object, the invalid line is skipped
        $this->assertCount(3, $data);
        $this->assertSame('PRLV SEPA EDF MDT/123', $data[0]['raw']);
        $this->assertSame('VIR SALAIRE', $data[2]['raw']);
    }

    public function testComing()
    {
        $data = Woob::getBankData('coming', '123@bnp');

        $this->assertCount(2, $data);
        $this->assertSame(12, $data[0]['type']);
    }

    public function testNoOutputReturnsNull()
    {
        $this->assertNull(Woob::getBankData('history', 'empty@bank'));
    }

    public function testErrorWithoutDataThrows()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("woob returned no data: Error(bnp): Unable to load module \"bnp\": No module named 'curl_cffi'");
        Woob::getBankData('history', 'broken@bank');
    }

    public function testFailureThrows()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("exit code: 1): AttributeError: 'NoneType' object has no attribute 'iter_accounts'");
        Woob::getBankData('history', 'fail@bank');
    }

    public function testIsTwoFactorError()
    {
        $this->assertTrue(Woob::isTwoFactorError('Error(creditmutuel): You have to run bank in interactive mode to perform a two-factor authentication'));
        $this->assertTrue(Woob::isTwoFactorError('AppValidation required'));
        // DecoupledValidation without resource: the bank demands the phone
        // validation while listing operations (same situation)
        $this->assertTrue(Woob::isTwoFactorError('Error(creditmutuel): The resource should be of type Recipient or Transfer, not "<class \'NoneType\'>"'));
        $this->assertFalse(Woob::isTwoFactorError("AttributeError: 'NoneType' object has no attribute 'iter_accounts'"));
        $this->assertFalse(Woob::isTwoFactorError(''));
    }

    public function testTwoFactorWaitsAndRetriesOnce()
    {
        $marker = sys_get_temp_dir() . '/carbure-fake-woob-2fa';
        @unlink($marker);
        Config::set('woob_2fa_wait', 0);
        Config::set('woob_2fa_retries', 1);
        try {
            ob_start();
            try {
                $data = Woob::getBankData('history', '2fa@bank');
            } finally {
                $output = ob_get_clean();
            }
        } finally {
            Config::set('woob_2fa_wait', 15);
            Config::set('woob_2fa_retries', 1);
            @unlink($marker);
        }

        // First call triggers the push and fails, the retry answers
        $this->assertCount(3, $data);
        $this->assertStringContainsString('Two-factor authentication required for 2fa@bank', $output);
    }

    public function testDecoupledValidationWhileListingIsRetried()
    {
        $marker = sys_get_temp_dir() . '/carbure-fake-woob-2fahistory';
        @unlink($marker);
        Config::set('woob_2fa_wait', 0);
        Config::set('woob_2fa_retries', 1);
        try {
            ob_start();
            try {
                $data = Woob::getBankData('history', '2fahistory@bank');
            } finally {
                $output = ob_get_clean();
            }
        } finally {
            Config::set('woob_2fa_wait', 15);
            Config::set('woob_2fa_retries', 1);
            @unlink($marker);
        }

        // First history call demands the phone validation (DecoupledValidation
        // without resource), the retry answers
        $this->assertCount(3, $data);
        $this->assertStringContainsString('Two-factor authentication required for 2fahistory@bank', $output);
    }

    public function testUnlockRetriesUntilBankListAnswers()
    {
        $lock = sys_get_temp_dir() . '/carbure-fake-woob-2fa-list';
        @unlink($lock);
        putenv('CARBURE_FAKE_WOOB_2FA_LIST=1');
        Config::set('woob_2fa_wait', 0);
        Config::set('woob_unlock_attempts', 3);
        try {
            ob_start();
            try {
                $accounts = Woob::unlockAccounts();
            } finally {
                $output = ob_get_clean();
            }
        } finally {
            putenv('CARBURE_FAKE_WOOB_2FA_LIST');
            Config::set('woob_2fa_wait', 15);
            Config::set('woob_unlock_attempts', 6);
            @unlink($lock);
        }

        // First list demands the phone validation, the next one answers
        $this->assertCount(2, $accounts);
        $this->assertSame('00012345678@bnp', $accounts[0]['id']);
        $this->assertStringContainsString('Unlocking bank, validate on your phone... (attempt 1/3)', $output);
        $this->assertStringContainsString('Bank unlocked (2 accounts)', $output);
    }

    public function testUnlockGivesUpAfterAttempts()
    {
        $lock = sys_get_temp_dir() . '/carbure-fake-woob-2fa-list';
        @unlink($lock);
        putenv('CARBURE_FAKE_WOOB_2FA_LIST=1');
        Config::set('woob_2fa_wait', 0);
        Config::set('woob_unlock_attempts', 1);
        try {
            ob_start();
            try {
                Woob::unlockAccounts();
                $this->fail('Unlock accepted');
            } catch (Exception $e) {
                $output = ob_get_clean();
                $this->assertStringContainsString('Recipient or Transfer', $e->getMessage());
                $this->assertStringNotContainsString('Bank unlocked', $output);
            }
        } finally {
            putenv('CARBURE_FAKE_WOOB_2FA_LIST');
            Config::set('woob_2fa_wait', 15);
            Config::set('woob_unlock_attempts', 6);
            @unlink($lock);
        }
    }

    public function testTwoFactorWithoutRetryThrows()
    {
        $marker = sys_get_temp_dir() . '/carbure-fake-woob-2fa';
        @unlink($marker);
        Config::set('woob_2fa_wait', 0);
        Config::set('woob_2fa_retries', 0);
        try {
            ob_start();
            try {
                Woob::getBankData('history', '2fa@bank');
                $this->fail('Two-factor failure accepted');
            } catch (Exception $e) {
                $output = ob_get_clean();
                $this->assertStringContainsString('two-factor authentication', $e->getMessage());
                $this->assertStringNotContainsString('Two-factor authentication required', $output);
            }
        } finally {
            Config::set('woob_2fa_wait', 15);
            Config::set('woob_2fa_retries', 1);
            @unlink($marker);
        }
    }

    public function testBankIdIsEscaped()
    {
        // Without escaping, the shell would run the injected command
        $marker = sys_get_temp_dir() . '/carbure-injection-' . uniqid();
        Woob::getBankData('history', "x@bnp; touch $marker");

        $this->assertFileDoesNotExist($marker);
    }

    public function testCommandOptions()
    {
        Config::set('woob_debug', true);
        Config::set('woob_auto_update', true);
        try {
            $command = (new ReflectionMethod(Woob::class, 'buildCommand'))->invoke(null, 'history', '1@bnp');
        } finally {
            Config::set('woob_debug', false);
        }

        $this->assertStringContainsString("bank history '1@bnp'", $command);
        $this->assertStringContainsString('--auto-update', $command);
        $this->assertStringContainsString('--debug', $command);
        $this->assertStringContainsString('-f json', $command);
    }

    public function testBackendsFileMadePrivate()
    {
        // A file of the banks readable by everyone (copied into a NAS shared folder)
        $home = sys_get_temp_dir() . '/carbure-woob-' . uniqid();
        mkdir("$home/.config/woob", 0777, true);
        chmod("$home/.config/woob", 0777);
        file_put_contents("$home/.config/woob/backends", "[bnp]\n");
        chmod("$home/.config/woob/backends", 0644);
        $previous = Config::get('woob_path');
        Config::set('woob_path', "env HOME=$home woob");
        try {
            $this->assertSame("env HOME=$home woob", Woob::path());
            clearstatcache();
            $this->assertSame(0600, fileperms("$home/.config/woob/backends") & 0777);
            $this->assertSame(0700, fileperms("$home/.config/woob") & 0777);
        } finally {
            Config::set('woob_path', $previous);
            unlink("$home/.config/woob/backends");
            rmdir("$home/.config/woob");
            rmdir("$home/.config");
            rmdir($home);
        }
    }
}
