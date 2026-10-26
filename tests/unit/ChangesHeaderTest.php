<?php

use Carbure\Resources\Changes;
use PHPUnit\Framework\TestCase;

class ChangesHeaderTest extends TestCase
{
    public function testTheVersionsAreSentByKind()
    {
        $this->assertSame('accounts=52, budgets=97, transactions=1835',
            Changes::header(['transactions' => 1835, 'budgets' => 97, 'accounts' => 52]));
    }

    public function testNothingToSendWithoutVersions()
    {
        $this->assertSame('', Changes::header([]));
    }
}
