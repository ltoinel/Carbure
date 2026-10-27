<?php

use Carbure\Lib\ApiError;
use Carbure\Lib\Db;
use Carbure\Resources\Category;
use Carbure\Resources\Transaction;

class TransactionSplitTest extends DatabaseTestCase
{
    private function id($uuid)
    {
        return (int)Db::queryOne("SELECT id FROM bank_transaction WHERE uuid = ?", "s", $uuid)['id'];
    }

    private function parts($id)
    {
        return Db::select("SELECT uuid, category, amount, label, date, pointed, user FROM bank_transaction WHERE split_of = ? ORDER BY id",
            "i", $id)->fetch_all(MYSQLI_ASSOC);
    }

    private function amount($id)
    {
        return Db::queryOne("SELECT amount FROM bank_transaction WHERE id = ?", "i", $id)['amount'];
    }

    private function assertApiError($code, callable $call)
    {
        try {
            $call();
            $this->fail("No error $code");
        } catch (ApiError $e) {
            $this->assertSame($code, $e->getErrorCode());
        }
    }

    public function testSplitKeepsTheRestOnTheTransaction()
    {
        $id = $this->id('u1');
        $total = fn() => Db::queryOne("SELECT SUM(amount) AS total FROM bank_transaction", "")['total'];
        $before = $total();

        $rows = Transaction::split($id, [['category' => 1, 'amount' => '-30.10']]);

        // The transaction first, then its part
        $this->assertSame([$id, null], [$rows[0]['id'], $rows[0]['split_of']]);
        $this->assertSame($id, $rows[1]['split_of']);
        $this->assertSame('-49.90', $this->amount($id));
        $part = $this->parts($id)[0];
        $this->assertSame([md5('u1#split1'), 1, '-30.10', 'CB SUPERMARCHE', $this->day(1), 0, 1],
            [$part['uuid'], $part['category'], $part['amount'], $part['label'], $part['date'], $part['pointed'], $part['user']]);
        // The amounts add up to the same total
        $this->assertSame($before, $total());
    }

    public function testSplitReplacesThePartsAndNoPartCancelsIt()
    {
        $id = $this->id('u1');
        Transaction::split($id, [['category' => 1, 'amount' => -30]]);
        Transaction::split($id, [['category' => 3, 'amount' => -10], ['category' => 1, 'amount' => '-20.5']]);

        $this->assertSame(['-10.00', '-20.50'], array_column($this->parts($id), 'amount'));
        $this->assertSame('-49.50', $this->amount($id));

        // The same parts again: same result
        Transaction::split($id, [['category' => 3, 'amount' => -10], ['category' => 1, 'amount' => '-20.5']]);
        $this->assertCount(2, $this->parts($id));
        $this->assertSame('-49.50', $this->amount($id));

        Transaction::split($id, []);
        $this->assertSame([], $this->parts($id));
        $this->assertSame('-80.00', $this->amount($id));
    }

    public function testSplitRefusesInvalidParts()
    {
        $id = $this->id('u1');
        $this->assertApiError('transaction_not_found', fn() => Transaction::split(999999, []));
        $this->assertApiError('split_sign', fn() => Transaction::split($id, [['category' => 1, 'amount' => 10]]));
        $this->assertApiError('split_sign', fn() => Transaction::split($id, [['category' => 1, 'amount' => 0]]));
        $this->assertApiError('split_total', fn() => Transaction::split($id, [['category' => 1, 'amount' => -50], ['category' => 3, 'amount' => -30]]));
        $this->assertApiError('split_limit', fn() => Transaction::split($id, array_fill(0, 11, ['category' => 1, 'amount' => -1])));
        $this->assertApiError('category_not_found', fn() => Transaction::split($id, [['category' => 99, 'amount' => -1]]));
        try {
            Transaction::split($id, [['amount' => -1]]);
            $this->fail('Part without category');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }

        // A part is not split again
        Transaction::split($id, [['category' => 1, 'amount' => -30]]);
        $part = (int)Db::queryOne("SELECT id FROM bank_transaction WHERE split_of = ?", "i", $id)['id'];
        $this->assertApiError('split_of_part', fn() => Transaction::split($part, [['category' => 3, 'amount' => -1]]));

        // Nothing changed by the refused ones
        $this->assertSame('-50.00', $this->amount($id));
        $this->assertCount(1, $this->parts($id));
    }

    public function testSplitIsKeptByTheSynchronization()
    {
        $transaction = [['date' => $this->day(4), 'amount' => -100, 'raw' => 'RETRAIT DAB PARIS', 'type' => 6]];
        Transaction::save($transaction, self::USER, false);
        $id = (int)Db::queryOne("SELECT id FROM bank_transaction WHERE label = 'RETRAIT DAB PARIS'", "")['id'];
        Transaction::split($id, [['category' => 1, 'amount' => -40], ['category' => 3, 'amount' => -20]]);

        $this->assertSame([], Transaction::save($transaction, self::USER, false));
        $this->assertSame('-40.00', $this->amount($id));
        $this->assertCount(2, $this->parts($id));
    }

    public function testDeleteAPartOrTheTransaction()
    {
        $this->loginAs(self::USER);
        $id = $this->id('u1');
        Transaction::split($id, [['category' => 1, 'amount' => -30], ['category' => 3, 'amount' => -20]]);
        $parts = Db::select("SELECT id FROM bank_transaction WHERE split_of = ? ORDER BY id", "i", $id)->fetch_all(MYSQLI_ASSOC);

        // A part gives its amount back, and is not remembered
        Transaction::delete($parts[0]['id']);
        $this->assertSame('-60.00', $this->amount($id));
        $this->assertNull(Db::queryOne("SELECT uuid FROM bank_transaction_deleted WHERE uuid = ?", "s", md5('u1#split1')));

        // The transaction goes with its parts
        Transaction::delete($id);
        $this->assertSame([], $this->parts($id));
        $this->assertNotNull(Db::queryOne("SELECT uuid FROM bank_transaction_deleted WHERE uuid = 'u1'", ""));
    }

    public function testPointingTheTransactionPointsItsParts()
    {
        $id = $this->id('u1');
        Transaction::split($id, [['category' => 1, 'amount' => -30]]);

        Transaction::updatePointed($id, true);
        $this->assertSame([1], array_column($this->parts($id), 'pointed'));
        Transaction::updatePointed($id, 'false');
        $this->assertSame([0], array_column($this->parts($id), 'pointed'));
    }

    public function testRulesLeaveTheSplitTransactionsAlone()
    {
        // SUPERMARCHE -> 2: the part in Alimentation (1) and the one left uncategorized stay so
        $id = $this->id('u1');
        Transaction::split($id, [['category' => 1, 'amount' => -30], ['category' => 0, 'amount' => -10]]);
        Db::execute("UPDATE bank_transaction SET category = 3 WHERE id = ?", "i", $id);

        Category::applyKeywords();
        Transaction::updateMissingCategories(true);

        $this->assertSame([1, 0], array_column($this->parts($id), 'category'));
        $this->assertSame(3, Db::queryOne("SELECT category FROM bank_transaction WHERE id = ?", "i", $id)['category']);
        // The other transactions still follow the rules
        $this->assertSame(2, Db::queryOne("SELECT category FROM bank_transaction WHERE uuid = 'u5'", "")['category']);
    }

    public function testRecurringCountsASplitTransactionOnce()
    {
        foreach ([3, 2, 1, 0] as $ago) {
            $date = $ago === 0 ? $this->day(10) : date('Y-m-d', strtotime(date('Y-m-01') . " -$ago months +9 days"));
            Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, card, pointed, user) VALUES (?, ?, ?, 7, 'CB NETFLIX.COM', 0, -13.49, '', 1, 1)",
                "sss", "netflix$ago", $date, $date);
        }
        Transaction::split($this->id('netflix0'), [['category' => 1, 'amount' => -5]]);

        $series = Transaction::recurring();
        $this->assertSame(['CB NETFLIX.COM'], array_column($series, 'label'));
        $this->assertSame(-13.49, $series[0]['amount']);
        $this->assertSame([$this->id('netflix0')], $series[0]['ids']);
    }
}
