<?php

class BankTest extends DatabaseTestCase
{
    /**
     * Run the synchronization and return the SSE output.
     */
    private function sync($token = null)
    {
        ob_start();
        try {
            Bank::getSync($token);
        } finally {
            $output = ob_get_clean();
        }
        return $output;
    }

    public function testGetAccountsOfTheUser()
    {
        $this->loginAs(self::USER);
        $this->assertSame([['bankId' => '111@bnp', 'account_number' => '111', 'bank_name' => 'bnp']], Bank::get());

        $this->loginAs(self::ADMIN);
        $this->assertCount(2, Bank::get());
    }

    public function testSync()
    {
        Db::query("DELETE FROM bank_transaction");
        $this->loginAs(self::ADMIN);

        $output = $this->sync();

        $this->assertStringContainsString('data: Syncing 111@bnp (coming)...', $output);
        $this->assertStringContainsString('data: Error syncing fail@bank', $output);
        $this->assertStringContainsString('data: Synchronization complete', $output);

        // coming: only the pending card payment (type 12) is kept; history: 3 transactions
        $rows = Db::query("SELECT * FROM bank_transaction ORDER BY rdate")->fetch_all(MYSQLI_ASSOC);
        $this->assertCount(4, $rows);
        $this->assertEquals([self::ADMIN], array_values(array_unique(array_column($rows, 'user'))));

        // Labels are cleaned and categorized from the keywords
        $labels = array_column($rows, 'category', 'label');
        $this->assertEquals(2, $labels['CB SUPERMARCHE']);
        $this->assertEquals(3, $labels['PRLV SEPA EDF ']);
        $this->assertEquals(4, $labels['VIR SALAIRE']);

        // A second synchronization does not duplicate anything
        $this->sync();
        $this->assertSame(4, Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction", "")['n']);

        // The admin device is notified for each of his accounts (success and failure)
        $titles = array_map(fn($r) => $r['payload']['aps']['alert']['title'], FakeApnsServer::requests());
        $this->assertContains('Bank synchronization successful', $titles);
        $this->assertContains('Bank synchronization failed !', $titles);
    }

    public function testLargeExpenseAlert()
    {
        Db::query("DELETE FROM bank_transaction");
        Db::query("UPDATE users SET alert_threshold = 50 WHERE id = 1");
        $this->loginAs(self::ADMIN);

        $this->sync();

        // Only the new expense of 60.10 reaches the threshold (42.50, 25 and the income do not)
        $alerts = array_values(array_filter(FakeApnsServer::requests(),
            fn($r) => $r['payload']['aps']['alert']['title'] === 'Dépense importante à vérifier'));
        $this->assertCount(1, $alerts);
        $this->assertSame('PRLV SEPA EDF : -60,10 €', $alerts[0]['payload']['aps']['alert']['body']);

        // Known transactions do not trigger the alert again
        FakeApnsServer::start();
        $this->sync();
        $titles = array_map(fn($r) => $r['payload']['aps']['alert']['title'], FakeApnsServer::requests());
        $this->assertNotContains('Dépense importante à vérifier', $titles);
    }

    public function testLargeExpenseAlertInEnglishAndDisabled()
    {
        Db::query("UPDATE users SET alert_threshold = 10, language = 'en' WHERE id = 1");

        $this->assertSame(1, Bank::alertLargeExpenses('111@bnp', [['label' => 'CB SHOP', 'amount' => -12.5]]));
        $this->assertSame('CB SHOP : -12.50 €', FakeApnsServer::requests()[0]['payload']['aps']['alert']['body']);
        $this->assertSame(0, Bank::alertLargeExpenses('111@bnp', []));

        Db::query("UPDATE users SET alert_threshold = NULL");
        $this->assertSame(0, Bank::alertLargeExpenses('111@bnp', [['label' => 'CB SHOP', 'amount' => -500]]));
    }

    public function testSyncWithToken()
    {
        Config::set('sync_token', 'scheduler-secret');
        try {
            $this->assertStringContainsString('Synchronization complete', $this->sync('scheduler-secret'));

            $_SERVER['HTTP_X_SYNC_TOKEN'] = 'scheduler-secret';
            $this->assertStringContainsString('Synchronization complete', $this->sync());
        } finally {
            Config::set('sync_token', '');
        }
    }

    public function testSyncRefusedWithoutCredentials()
    {
        Config::set('sync_token', 'scheduler-secret');
        try {
            $this->expectException(Error::class);
            $this->expectExceptionCode(403);
            $this->sync('wrong');
        } finally {
            Config::set('sync_token', '');
        }
    }

    public function testSyncAlreadyRunning()
    {
        $this->loginAs(self::ADMIN);

        // Another connection holds the lock
        $other = new mysqli(Config::get('db_hostname'), Config::get('db_username'), Config::get('db_password'), Config::get('db_name'), (int)Config::get('db_port'));
        $other->query("SELECT GET_LOCK('bank_sync', 0)");
        try {
            $this->assertSame("data: Synchronization already in progress\n\n", $this->sync());
        } finally {
            $other->close();
        }
    }

    public function testSyncAccountWithoutTransactions()
    {
        Db::query("DELETE FROM bank_account");
        Db::query("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES ('bank', 'empty', 2)");
        $this->loginAs(self::USER);

        $output = $this->sync();

        $this->assertStringContainsString('Notifying users of empty@bank', $output);
        $this->assertSame(0, Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction WHERE user=2", "")['n']);
    }

    public function testNotifyUnknownAccount()
    {
        Bank::notifyBankUsers('unknown@bank', true);
        $this->assertSame([], FakeApnsServer::requests());
    }

    public function testOwnerOfUnknownAccount()
    {
        $this->expectException(Exception::class);
        (new ReflectionMethod(Bank::class, 'getBankOwner'))->invoke(null, 'unknown@bank');
    }
}
