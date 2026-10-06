<?php

class TransactionIntegrationTest extends DatabaseTestCase
{
    private function transaction($uuid)
    {
        return Db::queryOne("SELECT * FROM bank_transaction WHERE uuid=?", "s", $uuid);
    }

    public function testGetCurrentMonth()
    {
        $labels = array_column(Transaction::get(), 'label');

        $this->assertCount(4, $labels);
        $this->assertNotContains('CB SUPERMARCHE ANCIEN', $labels);
    }

    public function testGetGivenMonth()
    {
        $this->assertSame('CB SUPERMARCHE ANCIEN', Transaction::get(1, 2020)[0]['label']);
    }

    public function testGetCategoryWithSubCategories()
    {
        // Alimentation (1) includes Supermarché (2)
        $this->assertSame(['CB SUPERMARCHE'], array_column(Transaction::get(null, null, 1), 'label'));
        $this->assertSame(['PRLV SEPA EDF '], array_column(Transaction::get(null, null, 3), 'label'));
        $this->assertSame([], Transaction::get(null, null, 4 + 100));
    }

    public function testSearch()
    {
        $labels = array_column(Transaction::search('supermarche'), 'label');

        // All periods, most recent first
        $this->assertSame(['CB SUPERMARCHE', 'CB SUPERMARCHE ANCIEN'], $labels);
        $this->assertCount(1, Transaction::search('supermarche', 1));
    }

    public function testSearchEscapesWildcards()
    {
        $this->assertSame(['CB 100% BIO'], array_column(Transaction::search('100%'), 'label'));
        $this->assertSame([], Transaction::search('_B'));
    }

    public function testSearchTooShort()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Transaction::search(' a ');
    }

    public function testPointAndUnpoint()
    {
        $id = $this->transaction('u1')['id'];

        Transaction::updatePointed($id);
        $this->assertSame(1, $this->transaction('u1')['pointed']);

        // Values coming from a query string or JSON
        Transaction::updatePointed($id, 'false');
        $this->assertSame(0, $this->transaction('u1')['pointed']);
    }

    public function testUpdateCategoryPointsTheTransaction()
    {
        $id = $this->transaction('u4')['id'];
        Transaction::updateCategory($id, 1);

        $this->assertSame(1, $this->transaction('u4')['category']);
        $this->assertSame(1, $this->transaction('u4')['pointed']);
    }

    public function testCountUnpointedOfTheMonth()
    {
        $this->assertSame(3, Transaction::countUnpointed());
    }

    public function testUpdateMissingCategories()
    {
        Db::query("UPDATE bank_transaction SET category=0");

        Transaction::updateMissingCategories();
        $this->assertSame(2, $this->transaction('u1')['category']);
        $this->assertSame(0, $this->transaction('u5')['category']);

        Transaction::updateMissingCategories(true);
        $this->assertSame(2, $this->transaction('u5')['category']);
        $this->assertSame(0, $this->transaction('u4')['category']);
    }

    public function testSaveIsIdempotent()
    {
        $transactions = [
            ['date' => $this->day(4), 'rdate' => $this->day(4), 'amount' => -9.99, 'raw' => "facture carte du 040126 l'epicerie carte 4974", 'type' => 7, 'card' => '4974'],
            ['date' => $this->day(27), 'amount' => 1500, 'raw' => 'VIR SALAIRE BONUS', 'type' => 1],
        ];

        ob_start();
        Transaction::save($transactions, self::USER);
        Transaction::save($transactions, self::USER);
        $progress = ob_get_clean();

        $this->assertStringContainsString("data: facture carte", $progress);
        $saved = Db::queryOne("SELECT * FROM bank_transaction WHERE label LIKE 'CB L%EPICERIE'", "");
        $this->assertSame(self::USER, $saved['user']);
        $this->assertSame(1, Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction WHERE label LIKE 'CB L%EPICERIE'", "")['n']);

        // Salary at the end of the month counts for the next month, real date is kept
        $salary = Db::queryOne("SELECT * FROM bank_transaction WHERE label='VIR SALAIRE BONUS'", "");
        $this->assertSame(date('Y-m-01', strtotime($this->day(27) . ' first day of next month')), $salary['date']);
        $this->assertSame($this->day(27), $salary['rdate']);
    }

    public function testPeriods()
    {
        $periods = Transaction::periods();
        $this->assertNotEmpty($periods);
        $keys = array_map(fn($p) => $p['year'] * 100 + $p['month'], $periods);
        // Newest first, once each
        $sorted = $keys;
        rsort($sorted);
        $this->assertSame($sorted, $keys);
        $this->assertSame($keys, array_values(array_unique($keys)));
        $count = Db::queryOne("SELECT COUNT(DISTINCT YEAR(date), MONTH(date)) AS n FROM bank_transaction", "")['n'];
        $this->assertCount((int)$count, $periods);
    }

    public function testSaveKeepsTheOriginOfTheTransaction()
    {
        $transaction = [['date' => $this->day(5), 'amount' => -12.5, 'raw' => 'PRLV SEPA ORIGINE', 'type' => 2]];

        // Saved before the origin was stored: a synchronization fills it, not a later one
        Transaction::save($transaction, self::USER, false);
        $this->assertNull(Db::queryOne("SELECT bank_name FROM bank_transaction WHERE label='PRLV SEPA ORIGINE'", "")['bank_name']);
        Transaction::save($transaction, self::USER, false, ['bank_name' => 'bnp', 'account_number' => '00012345678']);
        Transaction::save($transaction, self::USER, false, ['bank_name' => 'other', 'account_number' => '999']);

        $saved = Db::queryOne("SELECT bank_name, account_number FROM bank_transaction WHERE label='PRLV SEPA ORIGINE'", "");
        $this->assertSame(['bank_name' => 'bnp', 'account_number' => '00012345678'], $saved);
    }
}
