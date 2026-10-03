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
        $accounts = Bank::get();
        $this->assertCount(1, $accounts);
        $this->assertSame(['bankId' => '111@bnp', 'account_number' => '111', 'bank_name' => 'bnp'],
            array_diff_key($accounts[0], ['id' => true]));
        $this->assertGreaterThan(0, $accounts[0]['id']);

        $this->loginAs(self::ADMIN);
        $this->assertCount(2, Bank::get());
    }

    public function testCrud()
    {
        $this->loginAs(self::ADMIN);
        $before = count(Bank::accounts());

        // An administrator manages the accounts of every member of the household
        $account = Bank::create('222', 'bnp', self::USER);
        $this->assertSame('222@bnp', $account['bankId']);
        $this->assertSame(self::USER, $account['user_id']);
        $this->assertCount($before + 1, Bank::accounts());

        $updated = Bank::update($account['id'], '333', 'creditmutuel');
        $this->assertSame('333@creditmutuel', $updated['bankId']);
        $this->assertSame(self::USER, $updated['user_id']);
        $this->assertContains('333@creditmutuel', array_column(Bank::accounts(), 'bankId'));

        $this->assertTrue(Bank::delete($account['id']));
        $this->assertCount($before, Bank::accounts());
    }

    public function testUnknownOwner()
    {
        $this->loginAs(self::ADMIN);
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Bank::create('222', 'bnp', 999);
    }

    public function testCreateDuplicateAndInvalid()
    {
        $this->loginAs(self::ADMIN);
        try {
            Bank::create('111', 'bnp', self::USER);
            $this->fail('Duplicate accepted');
        } catch (Error $e) {
            $this->assertSame(409, $e->getCode());
        }

        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Bank::create('1; rm -rf /', 'bnp');
    }

    public function testUserCannotManageAccounts()
    {
        $this->loginAs(self::USER);
        $own = Bank::get()[0]['id'];

        foreach ([fn() => Bank::accounts(), fn() => Bank::create('222', 'bnp'), fn() => Bank::update($own, '222', 'bnp'),
                  fn() => Bank::delete($own), fn() => Bank::backends(), fn() => Bank::modules(), fn() => Bank::discover()] as $i => $call) {
            try {
                $call();
                $this->fail("Case $i accepted");
            } catch (Error $e) {
                $this->assertSame(403, $e->getCode(), "Case $i");
            }
        }
    }

    public function testDeleteUnknownAccount()
    {
        $this->loginAs(self::ADMIN);
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Bank::delete(99999);
    }

    public function testUpdateToAnAlreadyFollowedAccount()
    {
        $this->loginAs(self::ADMIN);
        $accounts = Bank::get();

        $this->expectException(Error::class);
        $this->expectExceptionCode(409);
        Bank::update($accounts[1]['id'], $accounts[0]['account_number'], $accounts[0]['bank_name']);
    }

    public function testBackends()
    {
        $this->loginAs(self::ADMIN);

        // Name and module only: the woob configuration (bank login) never leaves the server
        $this->assertSame([['name' => 'bnp', 'module' => 'bnp'], ['name' => 'cic_pro', 'module' => 'cic']], Bank::backends());
    }

    public function testModules()
    {
        $this->loginAs(self::ADMIN);
        $this->assertSame([
            ['module' => 'bnp', 'description' => 'BNP Paribas'],
            ['module' => 'boursorama', 'description' => 'Boursorama'],
            ['module' => 'cic', 'description' => 'CIC'],
        ], Bank::modules());
    }

    public function testDiscover()
    {
        $this->loginAs(self::ADMIN);
        $accounts = Bank::discover();

        $this->assertCount(2, $accounts);
        $this->assertSame('00012345678@bnp', $accounts[0]['bankId']);
        $this->assertSame('Compte chèques', $accounts[0]['label']);
        $this->assertFalse($accounts[0]['followed']);

        Bank::create('00012345678', 'bnp');
        $this->assertTrue(Bank::discover()[0]['followed']);
    }

    public function testSyncRecordsTheLastResultOfEachAccount()
    {
        $this->loginAs(self::ADMIN);
        $this->sync();

        $accounts = array_column(Bank::accounts(), null, 'bankId');
        $this->assertSame('OK', $accounts['111@bnp']['last_sync_status']);
        $this->assertNotNull($accounts['111@bnp']['last_sync_at']);
        $this->assertSame('ERROR', $accounts['fail@bank']['last_sync_status']);
        $this->assertNotEmpty($accounts['fail@bank']['last_sync_message']);
    }

    public function testSyncOneAccount()
    {
        $this->loginAs(self::ADMIN);
        ob_start();
        try {
            Bank::getSync(null, '111@bnp');
        } finally {
            $output = ob_get_clean();
        }
        $this->assertStringContainsString('Syncing 111@bnp', $output);
        $this->assertStringNotContainsString('fail@bank', $output);

        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Bank::getSync(null, 'unknown@bnp');
    }

    public function testSync()
    {
        Db::query("DELETE FROM bank_transaction");
        $this->loginAs(self::ADMIN);

        $output = $this->sync();

        $this->assertStringContainsString('data: Syncing 111@bnp (coming)...', $output);
        $this->assertStringContainsString('data: Done 111@bnp (coming): 1 received, 1 new', $output);
        $this->assertStringContainsString('data: Done 111@bnp (history): 3 received, 3 new', $output);
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
            $this->expectExceptionCode(401);
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
        $this->assertStringContainsString('Done empty@bank (history): 0 received, 0 new', $output);
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
