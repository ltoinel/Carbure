<?php

use Carbure\Lib\Db;
use Carbure\Resources\Backup;

class BackupTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs(self::ADMIN);
    }

    /**
     * Export through the route (raw JSON), decoded.
     *
     * @return array<string, mixed>
     */
    private function export(?string $datasets = null): array
    {
        return json_decode($this->callRoute('GET', '/backup/export', $datasets === null ? [] : ['datasets' => $datasets]), true);
    }

    private function rowCount(string $table): int
    {
        return (int)Db::queryOne("SELECT COUNT(*) AS n FROM `$table`", "")['n'];
    }

    public function testDatasetsWithTheirCounts()
    {
        $counts = array_column(Backup::get(), 'count', 'name');
        $this->assertSame(['categories' => 5, 'rules' => 3, 'budgets' => 2, 'insights' => 1, 'transactions' => 5, 'deleted_transactions' => 0], $counts);
    }

    public function testExport()
    {
        $backup = $this->export('transactions,categories');
        $this->assertSame('carbure-backup', $backup['format']);
        $this->assertSame(1, $backup['version']);
        $this->assertSame(['categories', 'transactions'], array_keys($backup['datasets']));
        $this->assertSame(['categories' => 5, 'transactions' => 5], $backup['counts']);
        $this->assertNotEmpty($backup['schema']);

        $transaction = $backup['datasets']['transactions'][0];
        $this->assertSame('u1', $transaction['uuid']);
        // The owner by username, the amount as a number
        $this->assertSame('admin', $transaction['user']);
        $this->assertSame(-80.0, $transaction['amount']);
        $this->assertFalse($transaction['pointed']);
        $this->assertSame(['id' => 0, 'name' => 'Aucune', 'parent_category' => 0, 'type' => 'HORS-BUDGET', 'icon' => 'help', 'color' => 'grey'],
            $backup['datasets']['categories'][0]);

        // Nothing secret
        $all = $this->callRoute('GET', '/backup/export');
        $this->assertStringNotContainsString(self::DEVICE_TOKEN, $all);
        $this->assertStringNotContainsString('$2y$', $all);
    }

    public function testExportThenImportRestoresTheData()
    {
        $backup = $this->export();
        $before = $backup['datasets'];

        // Changes after the backup
        Db::query("DELETE FROM bank_transaction WHERE uuid = 'u1'");
        Db::query("UPDATE bank_transaction_category SET name = 'Courses' WHERE id = 2");
        Db::query("INSERT INTO budget_insight (name, color, `sql`) VALUES ('X', 'red', 'SELECT 1 AS amount')");
        Db::query("INSERT INTO bank_transaction_deleted (uuid) VALUES ('gone')");

        $result = Backup::import($before);
        $this->assertSame(['categories' => 5, 'rules' => 3, 'budgets' => 2, 'insights' => 1, 'transactions' => 5, 'deleted_transactions' => 0], $result['imported']);
        $this->assertSame($before, $this->export()['datasets']);
    }

    public function testImportReplacesOnlyTheDatasetsGiven()
    {
        $result = Backup::import(['rules' => [['id' => 9, 'keyword' => 'BOULANGERIE', 'category' => 1, 'notify' => true]]]);
        $this->assertSame(['rules' => 1], $result['imported']);
        $this->assertSame(1, $this->rowCount('bank_transaction_category_keyword'));
        $this->assertSame(1, (int)Db::queryOne("SELECT notify FROM bank_transaction_category_keyword WHERE id = 9", "")['notify']);
        $this->assertSame(5, $this->rowCount('bank_transaction'));

        // An empty dataset empties its table
        Backup::import(['budgets' => []]);
        $this->assertSame(0, $this->rowCount('budget'));
    }

    public function testUsersAreMatchedByUsername()
    {
        $transaction = $this->export('transactions')['datasets']['transactions'][0];
        Backup::import([
            'transactions' => [
                ['id' => 1, 'uuid' => 'a', 'user' => 'user'] + $transaction,
                ['id' => 2, 'uuid' => 'b', 'user' => 'somebody-else'] + $transaction,
            ],
            'deleted_transactions' => [['uuid' => 'gone', 'deleted_at' => '2026-01-01 10:00:00', 'user_id' => null]],
        ]);
        $users = array_column(Db::execute("SELECT uuid, user FROM bank_transaction", "")->get_result()->fetch_all(MYSQLI_ASSOC), 'user', 'uuid');
        // Unknown here: the administrator who imports
        $this->assertSame(['a' => self::USER, 'b' => self::ADMIN], array_map('intval', $users));
        $this->assertSame(self::ADMIN, (int)Db::queryOne("SELECT user_id FROM bank_transaction_deleted", "")['user_id']);
    }

    public function testCategoriesStillUsedCannotDisappear()
    {
        Db::query("INSERT INTO budget_alert (user_id, category, month, budget, spent, percent, sent_at) VALUES (1, 3, '2026-01-01', 10, 20, 200, NOW())");
        $categories = array_values(array_filter($this->export('categories')['datasets']['categories'], fn($c) => $c['id'] !== 3));

        try {
            Backup::import(['categories' => $categories]);
            $this->fail('Accepted');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString('The rules refer to categories that do not exist: 3', $e->getMessage());
        }
        // Nothing changed
        $this->assertSame(5, $this->rowCount('bank_transaction_category'));
        $this->assertSame(1, $this->rowCount('budget_alert'));

        // With the data that used it: accepted, the alerts of the category deleted
        $budgets = array_values(array_filter($this->export('budgets')['datasets']['budgets'], fn($b) => $b['category'] !== 3));
        $transactions = array_values(array_filter($this->export('transactions')['datasets']['transactions'], fn($t) => $t['category'] !== 3));
        $rules = array_values(array_filter($this->export('rules')['datasets']['rules'], fn($r) => $r['category'] !== 3));
        Backup::import(['categories' => $categories, 'rules' => $rules, 'budgets' => $budgets, 'transactions' => $transactions]);
        $this->assertSame(4, $this->rowCount('bank_transaction_category'));
        $this->assertSame(0, $this->rowCount('budget_alert'));
    }

    public function testDefaultCategoryIsKept()
    {
        Backup::import(['categories' => [['id' => 1, 'name' => 'Alimentation', 'parent_category' => 0, 'type' => 'DEBIT', 'icon' => '', 'color' => '']],
            'rules' => [], 'budgets' => [], 'transactions' => []]);
        $this->assertSame([0, 1], array_map('intval', array_column(Db::execute("SELECT id FROM bank_transaction_category ORDER BY id", "")->get_result()->fetch_all(MYSQLI_ASSOC), 'id')));
    }

    public function testInvalidImportChangesNothing()
    {
        $cases = [
            [[], 'No dataset to import'],
            [['users' => []], 'Unknown dataset: users'],
            [['budgets' => [['id' => 1]]], 'budgets, row 1: invalid category'],
            [['insights' => [['id' => 1, 'name' => 'X', 'color' => 'red', 'icon' => null, 'sql' => 'DELETE FROM bank_transaction']]], 'insights, row 1: The query must be a SELECT'],
            [['categories' => [['id' => 1, 'name' => 'A', 'parent_category' => 2, 'type' => 'DEBIT', 'icon' => '', 'color' => ''],
                ['id' => 2, 'name' => 'B', 'parent_category' => 1, 'type' => 'DEBIT', 'icon' => '', 'color' => '']]], 'the parent of category 1 must be a top-level category'],
            // Refused by the database (unique keyword), after the rules were deleted
            [['rules' => [['id' => 1, 'keyword' => 'EDF', 'category' => 3, 'notify' => 0], ['id' => 2, 'keyword' => 'EDF', 'category' => 3, 'notify' => 0]]], 'The import failed, nothing was changed'],
        ];
        foreach ($cases as [$datasets, $message]) {
            try {
                Backup::import($datasets);
                $this->fail("Accepted: $message");
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode(), $e->getMessage());
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->assertSame(3, $this->rowCount('bank_transaction_category_keyword'));
        $this->assertSame(1, (int)Db::queryOne("SELECT @@FOREIGN_KEY_CHECKS AS f", "")['f']);
    }

    public function testUserCannotBackup()
    {
        $this->loginAs(self::USER);
        $this->assertAdminOnly(['GET /backup', 'GET /backup/export', 'POST /backup/import']);
    }
}
