<?php

use Carbure\Lib\Db;
use Carbure\Resources\Backup;
use Carbure\Resources\Bank;
use Carbure\Resources\Category;
use Carbure\Resources\Transaction;

/**
 * Origin of the category of a transaction (#341): the rules never overwrite a category
 * chosen by hand, and follow a corrected rule on the transactions they categorized.
 */
class CategorySourceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs(self::ADMIN);
    }

    private function row($uuid)
    {
        $row = Db::queryOne("SELECT id, category, category_source FROM bank_transaction WHERE uuid = ?", "s", $uuid);
        return [(int)$row['category'], $row['category_source']];
    }

    private function id($uuid)
    {
        return (int)Db::queryOne("SELECT id FROM bank_transaction WHERE uuid = ?", "s", $uuid)['id'];
    }

    public function testTheRulesAndTheUserSetTheOrigin()
    {
        // The transactions categorized before the migration: unknown
        $this->assertSame([2, null], $this->row('u1'));

        // A rule (u4 "CB 100% BIO", uncategorized)
        Category::createKeyword('BIO', 1);
        $this->assertSame([1, 'rule'], $this->row('u4'));

        // By hand (portal, mobile apps)
        Transaction::updateCategory($this->id('u4'), 2);
        $this->assertSame([2, 'manual'], $this->row('u4'));
        $listed = array_column(Transaction::get(), 'category_source', 'uuid');
        $this->assertSame('manual', $listed['u4']);

        // The synchronization categorizes the new transactions with the rules
        Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, card, pointed, user) VALUES
            ('u6', ?, ?, 2, 'PRLV EDF NOUVEAU', 0, -40.00, '', 0, 1)", "ss", $this->day(1), $this->day(1));
        $this->assertSame(1, Transaction::updateMissingCategories());
        $this->assertSame([3, 'rule'], $this->row('u6'));

        // A deleted category: uncategorized, of unknown origin
        Category::delete(3);
        $this->assertSame([0, null], $this->row('u6'));
    }

    public function testTheRulesNeverOverwriteACategoryChosenByHand()
    {
        Transaction::updateCategory($this->id('u1'), 4);
        Db::query("UPDATE bank_transaction SET category = 4 WHERE uuid = 'u2'");

        // Resynchronize: u2 (unknown origin) goes back to EDF, u1 chosen by hand stays
        Category::applyKeywords();
        $this->assertSame([[4, 'manual'], [3, 'rule']], [$this->row('u1'), $this->row('u2')]);

        // New and modified rules too
        $rule = Category::createKeyword('CB SUPER', 1);
        $this->assertSame([4, 'manual'], $this->row('u1'));
        $this->assertSame([1, 'rule'], $this->row('u5'));
        Category::updateKeyword($rule['id'], 'CB SUPER', 3);
        $this->assertSame([[4, 'manual'], [3, 'rule']], [$this->row('u1'), $this->row('u5')]);
    }

    public function testKeepCategorizedFollowsACorrectedRule()
    {
        // The example of #341: the EDF rule moved from Énergie to Logement
        Category::updateKeywordSettings(true);
        Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, card, pointed, user) VALUES
            ('u6', ?, ?, 2, 'PRLV EDF NOUVEAU', 0, -40.00, '', 0, 1)", "ss", $this->day(1), $this->day(1));
        Transaction::updateMissingCategories();
        $rule = array_column(Category::getKeywords(), 'id', 'keyword')['EDF'];

        $this->assertSame(1, Category::updateKeyword($rule, 'EDF', 1)['applied']);
        // Categorized by the rule: follows it; of unknown origin (u2): kept
        $this->assertSame([[1, 'rule'], [3, null]], [$this->row('u6'), $this->row('u2')]);
    }

    public function testSplitAndManualTransactionsAreChosenByHand()
    {
        $id = $this->id('u1');
        $rows = Transaction::split($id, [['category' => 1, 'amount' => -30]]);
        $this->assertSame('manual', Db::queryOne("SELECT category_source FROM bank_transaction WHERE id = ?", "i", $rows[1]['id'])['category_source']);

        $cash = (int)Bank::createManual('Espèces')['id'];
        $given = Transaction::createManual($cash, $this->day(2), 'Marché', -12, 1);
        $byRule = Transaction::createManual($cash, $this->day(2), 'EDF facture', -30);
        $none = Transaction::createManual($cash, $this->day(2), 'Divers', -3);
        $this->assertSame(['manual', 'rule', null], [$given['category_source'], $byRule['category_source'], $none['category_source']]);
    }

    public function testTheBackupKeepsTheOrigin()
    {
        Transaction::updateCategory($this->id('u1'), 4);
        $exported = json_decode(Backup::export('transactions'), true)['datasets']['transactions'];
        $sources = array_column($exported, 'category_source', 'uuid');
        $this->assertSame(['manual', null], [$sources['u1'], $sources['u2']]);

        // An older backup, without the column: unknown origin
        $old = array_map(function ($row) {
            unset($row['category_source']);
            return $row;
        }, $exported);
        Backup::import(['transactions' => $old]);
        $this->assertSame([4, null], $this->row('u1'));
        Backup::import(['transactions' => $exported]);
        $this->assertSame([4, 'manual'], $this->row('u1'));
    }

    public function testBeforeTheMigrationTheRulesWorkAsBefore()
    {
        Db::query("ALTER TABLE bank_transaction DROP COLUMN category_source");
        $ready = new ReflectionProperty(Transaction::class, 'sourceReady');
        $ready->setValue(null, null);
        try {
            Transaction::updateCategory($this->id('u1'), 4);
            $this->assertSame(4, (int)Db::queryOne("SELECT category FROM bank_transaction WHERE uuid = 'u1'", "")['category']);
            Category::applyKeywords();
            $this->assertSame(2, (int)Db::queryOne("SELECT category FROM bank_transaction WHERE uuid = 'u1'", "")['category']);
            $id = $this->id('u2');
            $this->assertCount(2, Transaction::split($id, [['category' => 1, 'amount' => -10]]));
            Category::delete(3);

            $exported = json_decode(Backup::export('transactions'), true)['datasets']['transactions'];
            $this->assertNull($exported[0]['category_source']);
            Backup::import(['transactions' => $exported]);
            $this->assertCount(count($exported), Db::select("SELECT id FROM bank_transaction", "")->fetch_all());
        } finally {
            Db::query("ALTER TABLE bank_transaction ADD COLUMN `category_source` varchar(10) DEFAULT NULL AFTER `category`");
            $ready->setValue(null, null);
        }
    }
}
