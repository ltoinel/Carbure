<?php

use Carbure\Lib\Config;
use Carbure\Lib\Db;
use Carbure\Resources\Tag;
use Carbure\Resources\Transaction;

/**
 * Synchronization after a fix of a woob module that changes the labels (#501, setting woob_relabel)
 */
class TransactionRelabelTest extends DatabaseTestCase
{
    private const ORIGIN = ['bank_name' => 'creditmutuel', 'account_number' => '42'];

    protected function tearDown(): void
    {
        Config::set('woob_relabel', '');
        parent::tearDown();
    }

    /** History sent by the module: before its fix (PAIEMENT PSC), after it (the shop) */
    private function history(bool $fixed)
    {
        return [
            ['type' => 7, 'raw' => $fixed ? 'CARTE SUPER U QUIMPER' : 'PAIEMENT PSC 2410', 'amount' => -24.10, 'date' => $this->day(4)],
            ['type' => 7, 'raw' => $fixed ? 'CARTE BOULANGERIE' : 'PAIEMENT PSC 2510', 'amount' => -3.20, 'date' => $this->day(3)],
            ['type' => 2, 'raw' => 'PRLV ORANGE', 'amount' => -39.99, 'date' => $this->day(2)],
        ];
    }

    private function rows()
    {
        return Db::select("SELECT id, label, category, pointed FROM bank_transaction WHERE bank_name = 'creditmutuel' ORDER BY amount", "")
            ->fetch_all(MYSQLI_ASSOC);
    }

    public function testOffByDefault()
    {
        Transaction::save($this->history(false), self::USER, false, self::ORIGIN);
        // As before: the fixed labels are new transactions, duplicates of the first ones
        $this->assertCount(2, Transaction::save($this->history(true), self::USER, false, self::ORIGIN));
        $this->assertCount(5, $this->rows());
    }

    public function testTheSavedTransactionsTakeTheNewLabels()
    {
        Config::set('woob_relabel', '1');
        Transaction::save($this->history(false), self::USER, false, self::ORIGIN);
        $before = array_column($this->rows(), null, 'label');
        $id = (int)$before['PAIEMENT PSC 2410']['id'];
        // Categorized and checked by the user, with a tag
        Transaction::updateCategory($id, 2);
        $tag = Tag::create('courses');
        Tag::tagTransactions([$id], [$tag['id']]);

        $this->assertSame([], Transaction::save($this->history(true), self::USER, false, self::ORIGIN));

        $after = array_column($this->rows(), null, 'id');
        $this->assertCount(3, $after);
        $this->assertSame('CARTE SUPER U QUIMPER', $after[$id]['label']);
        $this->assertEquals(2, $after[$id]['category']);
        $this->assertEquals(1, $after[$id]['pointed']);
        $this->assertEquals([$tag['id']], Tag::withTags([['id' => $id]])[0]['tags']);
        $this->assertSame('CARTE BOULANGERIE', $after[(int)$before['PAIEMENT PSC 2510']['id']]['label']);

        // The next synchronization recognizes them
        $this->assertSame([], Transaction::save($this->history(true), self::USER, false, self::ORIGIN));
        $this->assertCount(3, $this->rows());
    }

    public function testTwoPaymentsOfTheSameAmountTheSameDayAreNotGuessed()
    {
        Config::set('woob_relabel', '1');
        $coffee = fn($label) => ['type' => 7, 'raw' => $label, 'amount' => -2.50, 'date' => $this->day(2)];
        Transaction::save([$coffee('PAIEMENT PSC 1'), $coffee('PAIEMENT PSC 2')], self::USER, false, self::ORIGIN);

        // Two new labels for two saved: saved as new, nothing is guessed
        $this->assertCount(2, Transaction::save([$coffee('CAFE A'), $coffee('CAFE B')], self::USER, false, self::ORIGIN));
        $this->assertCount(4, $this->rows());
    }

    public function testAnotherAccountIsNotTouched()
    {
        Config::set('woob_relabel', '1');
        Transaction::save($this->history(false), self::USER, false, self::ORIGIN);
        $other = ['bank_name' => 'creditmutuel', 'account_number' => '43'];
        // The two fixed labels are new on that account (PRLV ORANGE has the same UUID: already known)
        $this->assertCount(2, Transaction::save($this->history(true), self::USER, false, $other));
        $this->assertContains('PAIEMENT PSC 2410', array_column($this->rows(), 'label'));
        $this->assertContains('PAIEMENT PSC 2510', array_column($this->rows(), 'label'));
    }
}
