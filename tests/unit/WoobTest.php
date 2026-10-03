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
