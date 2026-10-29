<?php

use Carbure\Lib\Db;
use Carbure\Lib\Migrator;

class MigratorTest extends DatabaseTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = carbure_test_tmp('carbure-migrations-' . uniqid());
        mkdir($this->dir);
        Db::query("DROP TABLE IF EXISTS schema_migrations, migration_test");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("$this->dir/*.sql"));
        rmdir($this->dir);
        Db::query("DROP TABLE IF EXISTS schema_migrations, migration_test");
        // As in sql/carbure.sql: the real migrations are applied (the other tests rely on it)
        (new Migrator(Db::getConnection()))->baseline();
        parent::tearDown();
    }

    private function migration($name, $sql)
    {
        file_put_contents("$this->dir/$name.sql", $sql);
    }

    public function testMigrateInOrderThenUpToDate()
    {
        $this->migration('2026-01-02_column', "-- Add a column\nALTER TABLE migration_test ADD COLUMN label varchar(10);\nINSERT INTO migration_test (label) VALUES ('ok');");
        $this->migration('2026-01-01_table', "CREATE TABLE migration_test (id int AUTO_INCREMENT PRIMARY KEY);");

        $migrator = new Migrator(Db::getConnection(), $this->dir);
        $this->assertNull($migrator->version());
        $this->assertSame(['2026-01-01_table', '2026-01-02_column'], $migrator->pending());

        $log = [];
        $this->assertSame(['2026-01-01_table', '2026-01-02_column'], $migrator->migrate(function ($m) use (&$log) { $log[] = $m; }));
        $this->assertSame('2026-01-02_column', $migrator->version());
        $this->assertSame([], $migrator->pending());
        $this->assertSame('ok', Db::queryOne("SELECT label FROM migration_test", "")['label']);
        $this->assertContains('2026-01-02_column: applied', $log);

        // Nothing more to do
        $this->assertSame([], $migrator->migrate());
    }

    public function testAppliedByHandIsOnlyRecorded()
    {
        Db::query("CREATE TABLE migration_test (id int PRIMARY KEY)");
        $this->migration('2026-01-01_table', "-- applied-if: SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migration_test'\nCREATE TABLE migration_test (id int PRIMARY KEY);");

        $migrator = new Migrator(Db::getConnection(), $this->dir);
        $this->assertSame(['2026-01-01_table'], $migrator->migrate());
        $this->assertSame('2026-01-01_table', $migrator->version());
    }

    public function testFailureStopsAndIsNotRecorded()
    {
        $this->migration('2026-01-01_bad', "CREATE TABLE migration_test (id int PRIMARY KEY);\nALTER TABLE nothing_here ADD COLUMN x int;");
        $this->migration('2026-01-02_next', "ALTER TABLE migration_test ADD COLUMN y int;");

        $migrator = new Migrator(Db::getConnection(), $this->dir);
        try {
            $migrator->migrate();
            $this->fail('A failing migration was accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('2026-01-01_bad', $e->getMessage());
        }
        $this->assertSame(['2026-01-01_bad', '2026-01-02_next'], $migrator->pending());
    }

    public function testDryRunAndBaseline()
    {
        $this->migration('2026-01-01_table', "CREATE TABLE migration_test (id int PRIMARY KEY);");
        $migrator = new Migrator(Db::getConnection(), $this->dir);

        $this->assertSame(['2026-01-01_table'], $migrator->migrate(null, true));
        $this->assertSame(['2026-01-01_table'], $migrator->pending());
        $this->assertNull(Db::queryOne("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migration_test'", ""));

        $migrator->baseline();
        $this->assertSame([], $migrator->pending());
    }

    public function testRealMigrationsAreDetectedOnTheCurrentSchema()
    {
        // The test database comes from sql/carbure.sql: every real migration must be in
        // it, with an applied-if check that recognizes it
        $migrator = new Migrator(Db::getConnection());
        $log = [];
        $migrator->migrate(function ($m) use (&$log) { $log[] = $m; }, true);
        $this->assertSame([], array_values(array_filter($log, fn($line) => !str_ends_with($line, 'already applied, recorded'))));
    }

    public function testLargestExpenseCountsASplitTransactionOnce()
    {
        // An installation of 1.11 with the starter query, and a query of its own
        $starter = "SELECT MIN(amount) AS amount FROM bank_transaction WHERE amount < 0 AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET' AND id <> 0) AND MONTH(date) = {month} AND YEAR(date) = {year}";
        $own = "SELECT MIN(amount) AS amount FROM bank_transaction WHERE amount < -10";
        Db::execute("INSERT INTO budget_insight (name, color, `sql`) VALUES ('Plus grosse dépense', 'pink', ?), ('Mine', 'red', ?)", "ss", $starter, $own);
        $name = '2026-11-04_insight_largest_expense';
        copy(dirname(__DIR__, 2) . "/sql/migrations/$name.sql", "$this->dir/$name.sql");

        $this->assertSame([$name], (new Migrator(Db::getConnection(), $this->dir))->migrate());

        $queries = array_column(Db::select("SELECT name, `sql` FROM budget_insight", "")->fetch_all(MYSQLI_ASSOC), 'sql', 'name');
        $expected = array_column(json_decode(file_get_contents(dirname(__DIR__, 2) . '/sql/starter.json'), true)['insights'], 'sql', 'fr');
        $this->assertSame($expected['Plus grosse dépense'], $queries['Plus grosse dépense']);
        $this->assertSame($own, $queries['Mine']);
    }
}
