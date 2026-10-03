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
}
