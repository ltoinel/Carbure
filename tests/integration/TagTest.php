<?php

use Carbure\Lib\ApiError;
use Carbure\Lib\Db;
use Carbure\Resources\Insight;
use Carbure\Resources\Tag;
use Carbure\Resources\Transaction;

class TagTest extends DatabaseTestCase
{
    private function assertApiError($code, callable $call)
    {
        try {
            $call();
            $this->fail("No error $code");
        } catch (ApiError $e) {
            $this->assertSame($code, $e->getErrorCode());
        }
    }

    private function id($uuid)
    {
        return (int)Db::queryOne("SELECT id FROM bank_transaction WHERE uuid = ?", "s", $uuid)['id'];
    }

    private function tagsOf($uuid)
    {
        return array_map('intval', array_column(Db::select("SELECT tag_id FROM transaction_tag WHERE transaction_id = ? ORDER BY tag_id",
            "i", $this->id($uuid))->fetch_all(MYSQLI_ASSOC), 'tag_id'));
    }

    public function testCreateChangeAndDeleteATag()
    {
        $tag = Tag::create('  #Voyage-Italie ', 'green', '2026-07-01', '2026-07-20');
        $this->assertSame(['voyage-italie', 'green', '2026-07-01', '2026-07-20', false],
            [$tag['name'], $tag['color'], $tag['start_date'], $tag['end_date'], $tag['active']]);

        // Still running: active; created today by default
        $works = Tag::create('travaux_2026');
        $this->assertSame([date('Y-m-d'), null, true], [$works['start_date'], $works['end_date'], $works['active']]);

        $changed = Tag::update($tag['id'], 'voyage-italie', 'blue', '2026-07-01', '');
        $this->assertSame(['blue', null, true], [$changed['color'], $changed['end_date'], $changed['active']]);

        // Active ones first, with their count and total
        $this->assertSame(['travaux_2026', 'voyage-italie'], array_column(Tag::get(), 'name'));
        $this->assertSame([0, 0.0], [Tag::get()[0]['count'], Tag::get()[0]['total']]);

        $this->assertTrue(Tag::delete($works['id']));
        $this->assertApiError('tag_not_found', fn() => Tag::delete($works['id']));
        $this->assertSame(['voyage-italie'], array_column(Tag::get(), 'name'));
    }

    public function testATagRefusesInvalidValues()
    {
        Tag::create('voyage', '', '2026-07-01');
        $this->assertApiError('tag_exists', fn() => Tag::create('#VOYAGE', '', '2026-07-01'));
        $this->assertApiError('tag_name_invalid', fn() => Tag::create('voyage italie'));
        $this->assertApiError('tag_name_invalid', fn() => Tag::create('#'));
        $this->assertApiError('tag_name_invalid', fn() => Tag::create(str_repeat('a', 41)));
        $this->assertApiError('category_style_invalid', fn() => Tag::create('rouge', 'not a color!'));
        $this->assertApiError('tag_date_invalid', fn() => Tag::create('noel', '', '2026-02-30'));
        $this->assertApiError('tag_period_invalid', fn() => Tag::create('noel', '', '2026-12-31', '2026-12-01'));
        $this->assertApiError('tag_not_found', fn() => Tag::update(999, 'x', '', '2026-01-01'));
        // Accents are letters
        $this->assertSame('été-2026', Tag::create('Été-2026')['name']);
    }

    public function testTagTransactionsAndSummary()
    {
        $trip = Tag::create('voyage', 'green', date('Y-m-01'))['id'];
        $other = Tag::create('autre')['id'];

        // A selection: u1 (-80, Supermarché), u2 (-50, Énergie), u3 (+2000, Salaire)
        $result = Tag::tagTransactions([$this->id('u1'), (string)$this->id('u2'), $this->id('u3')], [$trip, $other]);
        $this->assertSame(['updated' => 6], $result);
        $this->assertSame(['updated' => 0], Tag::tagTransactions([$this->id('u1')], [$trip]));
        $this->assertSame(['updated' => 2], Tag::tagTransactions([$this->id('u2'), $this->id('u3')], [], [$other]));
        $this->assertSame([$trip, $other], $this->tagsOf('u1'));
        $this->assertSame([$trip], $this->tagsOf('u2'));

        $summary = Tag::summary($trip);
        $this->assertSame([1870.0, 3], [$summary['total'], $summary['count']]);
        $this->assertSame([[2, -80.0, 1], [3, -50.0, 1], [4, 2000.0, 1]],
            array_map(fn($c) => [$c['category'], $c['total'], $c['count']], $summary['categories']));
        // Most recent first: u2 (day 2), u1 (day 1), u3 (day 0)
        $this->assertSame(['PRLV SEPA EDF ', 'CB SUPERMARCHE', 'VIR SALAIRE'], array_column($summary['transactions'], 'label'));
        $this->assertSame([$trip, $other], $summary['transactions'][1]['tags']);
        // Day by day, oldest first, with the running total
        $this->assertSame([[$this->day(0), 2000.0, 2000.0], [$this->day(1), -80.0, 1920.0], [$this->day(2), -50.0, 1870.0]],
            array_map(fn($d) => [$d['date'], $d['total'], $d['cumulative']], $summary['days']));

        $listed = array_column(Tag::get(), null, 'name');
        $this->assertSame([3, 1870.0], [$listed['voyage']['count'], $listed['voyage']['total']]);
    }

    public function testTagTransactionsRefusesInvalidSelections()
    {
        $trip = Tag::create('voyage')['id'];
        $this->assertApiError('tag_selection_limit', fn() => Tag::tagTransactions([], [$trip]));
        $this->assertApiError('tag_selection_limit', fn() => Tag::tagTransactions(range(1, 501), [$trip]));
        $this->assertApiError('tag_not_found', fn() => Tag::tagTransactions([$this->id('u1')], [999]));
        try {
            Tag::tagTransactions(['abc'], [$trip]);
            $this->fail('Invalid id');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }
        // An unknown transaction is skipped
        $this->assertSame(['updated' => 1], Tag::tagTransactions([$this->id('u1'), 999999], [$trip]));
    }

    public function testTheTransactionsGiveTheirTagsAndAreFoundByThem()
    {
        $trip = Tag::create('voyage-italie')['id'];
        Tag::tagTransactions([$this->id('u1'), $this->id('u5')], [$trip]);

        $byLabel = array_column(Transaction::get(), 'tags', 'label');
        $this->assertSame([$trip], $byLabel['CB SUPERMARCHE']);
        $this->assertSame([], $byLabel['VIR SALAIRE']);

        // Every period, most recent first
        $this->assertSame(['CB SUPERMARCHE', 'CB SUPERMARCHE ANCIEN'], array_column(Transaction::search('#Voyage-Italie'), 'label'));
        $this->assertSame([], Transaction::search('#inconnu'));
        $this->assertSame([[$trip]], array_column(Transaction::search('supermarche', 1), 'tags'));
    }

    public function testDeletingATagOrATransactionDeletesTheirLinks()
    {
        $this->loginAs(self::USER);
        $trip = Tag::create('voyage')['id'];
        Tag::tagTransactions([$this->id('u1'), $this->id('u2')], [$trip]);

        Transaction::delete($this->id('u1'));
        $this->assertSame(1, Tag::summary($trip)['count']);
        Tag::delete($trip);
        $this->assertSame([], $this->tagsOf('u2'));
        // The transaction itself is kept
        $this->assertSame(3, Db::queryOne("SELECT category FROM bank_transaction WHERE uuid = 'u2'", "")['category']);
    }

    public function testThePartsOfASplitTransactionGetItsTags()
    {
        $trip = Tag::create('voyage')['id'];
        $id = $this->id('u1');
        Tag::tagTransactions([$id], [$trip]);

        $rows = Transaction::split($id, [['category' => 1, 'amount' => -30]]);
        $this->assertSame([[$trip], [$trip]], array_column($rows, 'tags'));
        // A part leaves the project apart from its transaction
        Tag::tagTransactions([$rows[1]['id']], [], [$trip]);
        $this->assertSame([-50.0, 1], [Tag::summary($trip)['total'], Tag::summary($trip)['count']]);
    }

    public function testAnInsightReadsTheTags()
    {
        $trip = Tag::create('voyage')['id'];
        Tag::tagTransactions([$this->id('u1'), $this->id('u2')], [$trip]);
        $this->loginAs(self::ADMIN);

        $sql = "SELECT SUM(t.amount) AS amount FROM bank_transaction t JOIN transaction_tag x ON x.transaction_id = t.id
                JOIN tag g ON g.id = x.tag_id WHERE g.name = 'voyage'";
        $result = Insight::check($sql, (int)date('n'), (int)date('Y'));
        $this->assertTrue($result['valid']);
        $this->assertEquals(-130, $result['amount']);
    }
}
