<?php

use Carbure\Lib\ApiError;
use Carbure\Lib\Db;
use Carbure\Lib\Mailer;
use Carbure\Resources\Backup;
use Carbure\Resources\Insight;
use Carbure\Resources\InsightAlert;
use Carbure\Resources\Notification;
use Carbure\Resources\Transaction;

/**
 * Alerts when the value of the month of an enabled insight goes above or below the
 * threshold of a user (data set: the insight Dépenses, -140 € this month)
 */
class InsightAlertTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeSmtpServer::start();
        $this->loginAs(self::ADMIN);
    }

    protected function tearDown(): void
    {
        FakeSmtpServer::unconfigure();
        parent::tearDown();
    }

    public function testSetReadAndDeleteAnAlert()
    {
        $this->assertSame([], InsightAlert::get());
        $this->assertSame(['insight' => 1, 'direction' => 'above', 'threshold' => 100.5], InsightAlert::set('1', 'above', '100.5'));
        $this->assertSame([['insight' => 1, 'direction' => 'above', 'threshold' => 100.5]], InsightAlert::get());

        // Replaced, and each user has his own
        InsightAlert::set(1, 'below', 20);
        $this->assertSame([['insight' => 1, 'direction' => 'below', 'threshold' => 20.0]], InsightAlert::get());
        $this->loginAs(self::USER);
        $this->assertSame([], InsightAlert::get());

        $this->loginAs(self::ADMIN);
        $this->assertTrue(InsightAlert::delete(1));
        $this->assertSame([], InsightAlert::get());
        $this->assertApiError(404, 'insight_alert_not_found', fn() => InsightAlert::delete(1));
    }

    /**
     * @dataProvider refusedAlerts
     */
    public function testRefusedAlerts($insight, $direction, $threshold, $status, $code)
    {
        $this->assertApiError($status, $code, fn() => InsightAlert::set($insight, $direction, $threshold));
    }

    public static function refusedAlerts()
    {
        return [
            'unknown insight' => [999, 'above', 10, 404, 'insight_not_found'],
            'not an id' => ['abc', 'above', 10, 404, 'insight_not_found'],
            'direction' => [1, 'over', 10, 400, 'insight_alert_direction_invalid'],
            'negative' => [1, 'above', -1, 400, 'insight_alert_threshold_invalid'],
            'not a number' => [1, 'above', 'lots', 400, 'insight_alert_threshold_invalid'],
            'too large' => [1, 'above', '1e12', 400, 'insight_alert_threshold_invalid'],
        ];
    }

    public function testOnlyAnEnabledInsightAlerts()
    {
        InsightAlert::set(1, 'above', 100);
        // Disabling the insight drops its alerts, and it cannot get one back while hidden
        Insight::setEnabled(1, false);
        $this->assertSame(0, $this->alerts());
        $this->assertApiError(400, 'insight_alert_disabled', fn() => InsightAlert::set(1, 'above', 100));

        // Deleting it too
        Insight::setEnabled(1, true);
        InsightAlert::set(1, 'above', 100);
        Insight::delete(1);
        $this->assertSame(0, $this->alerts());
    }

    public function testOffByDefault()
    {
        InsightAlert::set(1, 'above', 100);

        $this->assertSame(0, InsightAlert::check());
        $this->assertSame([], FakePushRelay::requests());
        $this->assertSame([], FakeSmtpServer::messages());
        $this->assertFalse(Notification::preferences(self::ADMIN)['insight']['push']);
    }

    public function testAlertAboveOncePerMonthAndAgainWhenChanged()
    {
        $this->subscribe(self::ADMIN, true, false);
        InsightAlert::set(1, 'above', 140);
        // 140 € is not above 140 €
        $this->assertSame(0, InsightAlert::check());

        $this->spend(-0.01);
        $this->assertSame(1, InsightAlert::check());
        $requests = FakePushRelay::requests();
        $this->assertCount(1, $requests);
        $this->assertSame('Insight au-dessus du seuil : Dépenses', $requests[0]['payload']['aps']['alert']['title']);
        $this->assertSame('140,01 € ce mois-ci (seuil : 140,00 €)', $requests[0]['payload']['aps']['alert']['body']);
        $this->assertSame(Transaction::countUnpointed(), $requests[0]['payload']['aps']['badge']);
        $this->assertSame($this->day(0), Db::queryOne("SELECT notified_month FROM insight_alert", "")['notified_month']);

        // Not again this month
        FakePushRelay::start();
        $this->spend(-50);
        $this->assertSame(0, InsightAlert::check());
        $this->assertSame([], FakePushRelay::requests());

        // A new threshold arms it again
        InsightAlert::set(1, 'above', 150);
        $this->assertSame(1, InsightAlert::check());
    }

    public function testAlertBelowByEmailInTheLanguageOfTheUser()
    {
        $this->subscribe(self::USER, false, true);
        $this->loginAs(self::USER);
        InsightAlert::set(1, 'below', 200);

        $this->assertSame(1, InsightAlert::check());
        $this->assertSame([], FakePushRelay::requests());
        $messages = FakeSmtpServer::messages();
        $this->assertCount(1, $messages);
        $this->assertSame(['user@example.com'], $messages[0]['to']);
        $this->assertStringContainsString('Subject: ' . Mailer::encodeHeader('[Carbure] Insight below its threshold: Dépenses'), $messages[0]['data']);
        $this->assertStringContainsString('140.00 € this month (threshold: 200.00 €)', quoted_printable_decode($messages[0]['data']));
    }

    public function testEachInsightRunOnceAndAFailingQueryNeverAlerts()
    {
        $this->subscribe(self::ADMIN, true, false);
        $this->subscribe(self::USER, true, false);
        InsightAlert::set(1, 'above', 10);
        $this->loginAs(self::USER);
        InsightAlert::set(1, 'above', 10);
        $this->assertSame(2, InsightAlert::check());

        // A stored query that fails gives no value: nothing is sent
        Db::query("UPDATE insight_alert SET notified_month = NULL");
        Db::query("UPDATE budget_insight SET `sql` = 'SELECT amount FROM users AS amount'");
        $this->assertSame(0, InsightAlert::check());
    }

    public function testAlertAfterAnImportOfTheCurrentMonth()
    {
        $this->subscribe(self::ADMIN, true, false);
        InsightAlert::set(1, 'above', 200);

        $date = date('d/m/Y', strtotime($this->day(4)));
        Transaction::import(base64_encode("Date;Libellé;Montant\n$date;CB BOULANGERIE IMPORT;-75,00\n"), 'releve.csv');

        $titles = array_map(fn($request) => $request['payload']['aps']['alert']['title'], FakePushRelay::requests());
        $this->assertContains('Insight au-dessus du seuil : Dépenses', $titles);
    }

    public function testAlertsOfTheInsightsNoLongerThereDroppedByAnImport()
    {
        Insight::create('Revenus', 'green', 'SELECT SUM(amount) AS amount FROM bank_transaction WHERE amount > 0 AND MONTH(date)={month} AND YEAR(date)={year}');
        InsightAlert::set(1, 'above', 100);
        InsightAlert::set(2, 'below', 100);

        // A backup with only the first insight: the alert of the second one goes away
        $exported = json_decode(Backup::export('insights'), true)['datasets']['insights'];
        Backup::import(['insights' => [$exported[0]]]);
        $this->assertSame([1], array_column(InsightAlert::get(), 'insight'));
    }

    public function testBeforeTheMigrationNoAlert()
    {
        Db::query("DROP TABLE insight_alert");
        $ready = new ReflectionProperty(InsightAlert::class, 'ready');
        $ready->setValue(null, null);
        try {
            $this->assertSame([], InsightAlert::get());
            $this->assertSame(0, InsightAlert::check());
            // An insight is still disabled, and an import still works
            Insight::setEnabled(1, false);
            Backup::import(['insights' => json_decode(Backup::export('insights'), true)['datasets']['insights']]);
            $this->assertApiError(404, 'insight_alert_not_found', fn() => InsightAlert::delete(1));
            Insight::setEnabled(1, true);
            $this->assertApiError(409, 'insight_migration_pending', fn() => InsightAlert::set(1, 'above', 10));
        } finally {
            Db::query(implode("\n", array_filter(explode("\n", file_get_contents(__DIR__ . '/../../sql/migrations/2026-11-03_insight_alert.sql')),
                fn($line) => !str_starts_with($line, '--'))));
            $ready->setValue(null, null);
        }
    }

    public function testAlertsDeletedWithTheUser()
    {
        $this->loginAs(self::USER);
        InsightAlert::set(1, 'above', 10);
        Db::execute("DELETE FROM users WHERE id = ?", "i", self::USER);
        $this->assertSame(0, $this->alerts());
    }

    /**
     * The call fails with this status and stable code
     */
    private function assertApiError($status, $code, callable $call)
    {
        try {
            $call();
            $this->fail("Expected $code");
        } catch (ApiError $e) {
            $this->assertSame([$status, $code], [$e->getCode(), $e->getErrorCode()]);
        }
    }

    /**
     * Turn the insight alerts on for a user
     */
    private function subscribe($userId, $push, $email)
    {
        Db::execute("INSERT INTO user_notification (user_id, kind, push, email) VALUES (?, 'insight', ?, ?)", "iii", $userId, (int)$push, (int)$email);
    }

    /**
     * An expense of the current month
     */
    private function spend($amount)
    {
        Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, card, pointed, user) VALUES (?, ?, ?, 7, 'CB TEST', 1, ?, '', 1, 1)",
            "sssd", uniqid('t', true), $this->day(5), $this->day(5), $amount);
    }

    /**
     * Number of alerts set
     */
    private function alerts()
    {
        return (int)Db::queryOne("SELECT COUNT(*) AS n FROM insight_alert", "")['n'];
    }
}
