<?php

class BudgetTest extends DatabaseTestCase
{
    private function byName($rows)
    {
        return array_column($rows, null, 'name');
    }

    public function testTopLevelCategories()
    {
        $budget = $this->byName(Budget::get());

        // Sub-category expenses count for their parent
        $this->assertEquals(200, $budget['Alimentation']['budget']);
        $this->assertEquals(80, $budget['Alimentation']['consummed']);
        $this->assertEquals(40, $budget['Alimentation']['progress']);
        $this->assertEquals(50, $budget['Énergie']['consummed']);
        $this->assertArrayNotHasKey('Supermarché', $budget);
    }

    public function testSubCategories()
    {
        $budget = $this->byName(Budget::get(null, null, 1));

        $this->assertSame(['Supermarché'], array_keys($budget));
        $this->assertEquals(0, $budget['Supermarché']['budget']);
    }

    public function testCreateOrUpdate()
    {
        Budget::create(1, 300);
        Budget::create(4, 1000, date('n'), date('Y'));

        $budget = $this->byName(Budget::get());
        $this->assertEquals(300, $budget['Alimentation']['budget']);
        $this->assertEquals(1000, $budget['Salaire']['budget']);
    }

    public function testTrends()
    {
        $trends = Budget::getTrends();

        $this->assertCount(24, $trends);
        $this->assertSame(date('Y-m'), $trends[23]['month']);

        // Current month of the data set: -80 -50 expenses, +2000 income,
        // -10 in category 0 which is off-budget
        $current = $trends[23];
        $this->assertEquals(130, $current['debit']);
        $this->assertEquals(2000, $current['credit']);
        $this->assertEquals(-10, $current['offBudget']);
        // No savings category in the data set
        $this->assertEquals(0, $current['savings']);
        // Only the top-level budgets: Alimentation 200 + Énergie 100
        $this->assertEquals(300, $current['planned']);

        // Empty months are present with zeros
        $this->assertEquals(0, $trends[0]['debit']);
    }

    public function testTrendsSavingsFromTheSavingsCategory()
    {
        // "Épargne" (accent and case insensitive) with a sub-category
        Db::query("INSERT INTO bank_transaction_category (id, name, parent_category, type, icon, color) VALUES (20, 'Épargne', 0, 'HORS-BUDGET', 'banknote', 'green')");
        Db::query("INSERT INTO bank_transaction_category (id, name, parent_category, type, icon, color) VALUES (21, 'Livret A', 20, 'HORS-BUDGET', 'banknote', 'green')");
        $date = date('Y-m-05');
        foreach ([['s1', 20, -300], ['s2', 21, -200], ['s3', 21, 50]] as [$uuid, $category, $amount]) {
            Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, user)
                VALUES (?, ?, ?, 1, 'VIR EPARGNE', ?, ?, 1)", "sssid", $uuid, $date, $date, $category, $amount);
        }

        // 300 + 200 put aside, 50 taken back
        $this->assertEquals(450, Budget::getTrends()[23]['savings']);
    }

    public function testTrendsWithOffset()
    {
        // The 3 months before the last 3 months
        $trends = Budget::getTrends(3, 3);

        $this->assertCount(3, $trends);
        $this->assertSame(date('Y-m', strtotime(date('Y-m-01') . ' -3 months')), $trends[2]['month']);
        $this->assertEquals(0, $trends[2]['debit']);
    }

    public function testTrendsOffBudgetAndBounds()
    {
        Db::query("UPDATE bank_transaction_category SET type='HORS-BUDGET' WHERE id=3");

        $trends = Budget::getTrends(1);
        $this->assertCount(1, $trends);
        $this->assertEquals(80, $trends[0]['debit']);
        $this->assertEquals(-60, $trends[0]['offBudget']);

        $this->assertCount(60, Budget::getTrends(500));
    }

    public function testInsights()
    {
        $insights = Budget::getInsights();

        $this->assertSame('Dépenses', $insights[0]['name']);
        $this->assertEquals(-140, $insights[0]['amount']);
        $this->assertArrayNotHasKey('sql', $insights[0]);
    }

    public function testInsightsCastTheDate()
    {
        // Used to be injected as is in the SQL: now cast to 1, i.e. January 2020 only
        $this->assertEquals(-5, Budget::getInsights('1) OR (1=1', 2020)[0]['amount']);
    }

    public function testInsightsHistory()
    {
        $history = Budget::getInsightsHistory();

        $this->assertCount(12, $history[1]['history']);
        $this->assertEquals(-140, $history[1]['history'][date('n') - 1]['amount']);
    }
}
