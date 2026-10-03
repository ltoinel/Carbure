<?php

/**
 * Budget.php
 *
 * Budget class to calculate the budget consumption by category.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Budget {
    
    /**
     * Get the current budget consumption by category.
     *
     * @param int|null $month    The month to get the budget
     * @param int|null $year     The year to get the budget
     * @param int      $category The parent category id
     * @return array The list of budget items
     */
    #[ApiRoute('/budget', method: 'GET')]
    public static function get($month = null, $year = null, $category = 0)
    {
        // If the date are not set we use the current month
        if (empty($month)) {
            $month = date('m');
        }

        if (empty($year)) {
            $year = date('Y');
        }

        // Return all the categories with the budget
        $sql = "SELECT c.id, c.name, c.type, c.icon, c.color, IFNULL(b.amount, 0) as budget, IFNULL(ABS(sum(t.amount)),0) as consummed
        , IFNULL(ABS(ROUND(sum(t.amount)/b.amount*100)),0) as progress 
            FROM bank_transaction_category c 

            LEFT JOIN budget b ON 
                b.category=c.id 
                and MONTH(b.date)=?
                and YEAR(b.date)=?

            LEFT JOIN bank_transaction t ON 
                (t.category=c.id 
                or t.category IN (SELECT id from bank_transaction_category where parent_category=c.id and parent_category!=0))
                and MONTH(t.date)=?
                and YEAR(t.date)=?

            WHERE c.parent_category=?
    
            GROUP BY c.id, c.name, c.type, c.icon, c.color, b.amount ORDER BY consummed desc";

        $stmt = Db::execute($sql, "iiiii", $month, $year, $month, $year, $category);
        $resultBudget = $stmt->get_result();

        // Collect the rows
        $budget = array();
        if ($resultBudget->num_rows > 0) {

            while ($row = $resultBudget->fetch_assoc()) {
                $budget[] = $row;
            }
        }

        return $budget;
    }

    /**
     * Set the budget for a category.
     *
     * @param int      $category The category to set the budget
     * @param float    $amount   The amount of the budget
     * @param int|null $month    The month of the budget
     * @param int|null $year     The year of the budget
     * @return bool True on success
     */
    #[ApiRoute('/budget', method: 'POST')]
    public static function create($category, $amount, $month = null, $year = null)
    {
        // If the date are not set we use the current month
        if (empty($month)) {
            $month = date('m');
        }

        if (empty($year)) {
            $year = date('Y');
        }
  
        // Insert or update the budget for the given category and date
        $date = $year . "-" . $month . "-01";
        $sql = "INSERT INTO budget (date, amount, category) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE amount=?";

        Db::execute($sql, "sdid", $date, $amount, $category, $amount);

        return true;
    }   


    /**
     * Get the monthly trends of the household over the last months.
     *
     * For each month (oldest first):
     * - debit:     expenses, off-budget categories excluded (positive amount)
     * - credit:    incomes, off-budget categories excluded
     * - offBudget: net amount of the off-budget categories (e.g. transfers to savings)
     * - planned:   sum of the budgets of the top-level categories
     * - savings:   credit - debit (off-budget categories excluded)
     *
     * @param int $months The number of months (1 to 60)
     * @param int $offset Number of months between the current month and the last month
     *                    of the period (0 = up to the current month, used for comparisons)
     * @return array The list of months
     */
    #[ApiRoute('/budget/trends', method: 'GET')]
    public static function getTrends($months = 24, $offset = 0)
    {
        $months = max(1, min(60, (int)$months));
        $offset = max(0, min(120, (int)$offset));
        $last = date('Y-m-01', strtotime(date('Y-m-01') . " -$offset months"));
        $from = date('Y-m-01', strtotime("$last -" . ($months - 1) . ' months'));
        $to = date('Y-m-01', strtotime("$last +1 month"));

        // Empty months are kept with zero values
        $trends = [];
        for ($i = 0; $i < $months; $i++) {
            $month = date('Y-m', strtotime("$from +$i months"));
            $trends[$month] = ['month' => $month, 'debit' => 0, 'credit' => 0, 'offBudget' => 0, 'planned' => 0, 'savings' => 0];
        }

        $sql = "SELECT DATE_FORMAT(t.date, '%Y-%m') AS month,
                SUM(CASE WHEN IFNULL(c.type, '') <> 'HORS-BUDGET' AND t.amount < 0 THEN -t.amount ELSE 0 END) AS debit,
                SUM(CASE WHEN IFNULL(c.type, '') <> 'HORS-BUDGET' AND t.amount > 0 THEN t.amount ELSE 0 END) AS credit,
                SUM(CASE WHEN c.type = 'HORS-BUDGET' THEN t.amount ELSE 0 END) AS offBudget,
                SUM(CASE WHEN IFNULL(c.type, '') <> 'HORS-BUDGET' THEN t.amount ELSE 0 END) AS savings
            FROM bank_transaction t
            LEFT JOIN bank_transaction_category c ON c.id = t.category
            WHERE t.date >= ? AND t.date < ?
            GROUP BY month";
        foreach (Db::execute($sql, "ss", $from, $to)->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            foreach (['debit', 'credit', 'offBudget', 'savings'] as $key) {
                $trends[$row['month']][$key] = round((float)$row[$key], 2);
            }
        }

        $sql = "SELECT DATE_FORMAT(b.date, '%Y-%m') AS month, SUM(b.amount) AS planned
            FROM budget b
            JOIN bank_transaction_category c ON c.id = b.category AND c.parent_category = 0 AND c.id <> 0
            WHERE b.date >= ? AND b.date < ?
            GROUP BY month";
        foreach (Db::execute($sql, "ss", $from, $to)->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $trends[$row['month']]['planned'] = round((float)$row['planned'], 2);
        }

        return array_values($trends);
    }

    /**
     * Get the insights data.
     *
     * @param int|null $month The month to get the insights
     * @param int|null $year  The year to get the insights
     * @return array The list of insights
     */
    #[ApiRoute('/budget/insights', method: 'GET')]
    public static function getInsights($month = null, $year = null)
    {
        // If the date are not set we use the current month
        if (empty($month)) {
            $month = date('m');
        }
        if (empty($year)) {
            $year = date('Y');
        }

        $sql = "SELECT * from budget_insight";

        // For earch insights in database.     
        $result = Db::query($sql);
        $insights = array();
        if ($result->num_rows > 0) {

            // We fill the date into the SQL requests
            while ($row = $result->fetch_assoc()) {
                $row["sql"] = str_replace("{month}", (int)$month, $row["sql"]);
                $row["sql"] = str_replace("{year}", (int)$year, $row["sql"]);
                $insights[] = $row;
            }
        }

        // For each insights we calculate the SQL associated
        foreach ($insights as &$insight) {
            $result = Db::query($insight["sql"]);
            $amount = $result->fetch_assoc()["amount"]; 
            $insight["amount"] = ($amount == null) ? 0 : $amount;
            unset($insight["sql"]);
        }

        return $insights;
    }

    /**
     * Get the year insights data.
     *
     * @param int|null $year The year to get the insights
     * @return array The list of insights by month
     */
    #[ApiRoute('/budget/insights/history', method: 'GET')]
    public static function getInsightsHistory($year = null)
    {
        // If the date are not set we use the current year
        if (empty($year)) {
            $year = date('Y');
        }

       // insights map
       $insights = array();
         
        // for each month of the year
        for ($month = 1; $month <= 12; $month++) {
            
            // call getInsights
            $monthInsights = self::getInsights($month, $year);

            // Put the results into the insights map
            foreach ($monthInsights as $insight) {
                if (!isset($insights[$insight["id"]])) {
                    $insights[$insight["id"]] = array(
                        "id" => $insight["id"],
                        "name" => $insight["name"],
                        "history" => array()
                    );
                }
                $insights[$insight["id"]]["history"][] = array(
                    "month" => $month,
                    "amount" => $insight["amount"]
                );
            }
        }

        return $insights;
    }
}
