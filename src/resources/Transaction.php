<?php

/**
 * Transaction.php
 *
 * Bank transaction management
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

// Credit or Debit
// 1 : VIREMENT
// 2 : PRELEVEMENT
// 3 : CHEQUE
// 4 : REMISE CHEQUE
// 5 : REMBOURSEMENT
// 6 : RETRAIT DAB
// 7 : FACTURE CARTE
// 8 : DEPENSE
// 9 : COMMISSIONS
// 12 : EN COURS CARTE  

require_once "Category.php";

final class Transaction {
    
    /**
     * Return the transactions of a month, optionally of one category.
     *
     * @param int|null $month    The month of the transactions
     * @param int|null $year     The year of the transactions
     * @param int|null $category Only this category and its sub-categories (optional)
     * @return array The list of transactions
     */
    #[ApiRoute('/transaction', method: 'GET')]
    public static function get($month = null, $year = null, $category = null)
    {
        // Default to the current month
        if (empty($month)) {
            $month = date('m');
        }
        if (empty($year)) {
            $year = date('Y');
        }

        // Date range (uses the index on the date)
        $from = sprintf('%04d-%02d-01', (int)$year, (int)$month);
        $to = date('Y-m-d', strtotime("$from +1 month"));

        if ($category === null || $category === '') {
            $sql = "SELECT * FROM bank_transaction WHERE date >= ? AND date < ? ORDER BY rdate DESC";
            $stmt = Db::execute($sql, "ss", $from, $to);
        } else {
            $sql = "SELECT * FROM bank_transaction WHERE date >= ? AND date < ?
                    AND (category = ? OR category IN (SELECT id FROM bank_transaction_category WHERE parent_category = ? AND id <> 0))
                    ORDER BY rdate DESC";
            $stmt = Db::execute($sql, "ssii", $from, $to, $category, $category);
        }
        $result = $stmt->get_result();
        $transactions = array();

        // Collect the rows
        if ($result->num_rows > 0) {

            while ($row = $result->fetch_assoc()) {
                $transactions[] = $row;
            }
        }

        return $transactions;
    }

    /**
     * Search transactions by label, most recent first.
     *
     * @param string $query The text to find in the label (2 characters minimum)
     * @param int    $limit The maximum number of transactions (1 to 500)
     * @return array The list of transactions
     * @throws Error If the query is too short
     */
    #[ApiRoute('/transaction/search', method: 'GET')]
    public static function search($query, $limit = 100)
    {
        $query = trim((string)$query);
        if (mb_strlen($query) < 2) {
            throw new Error("Search query must contain at least 2 characters", 400);
        }
        $limit = max(1, min(500, (int)$limit));

        // Labels are stored in upper case; LIKE wildcards are escaped
        $pattern = '%' . addcslashes(strtoupper($query), '%_\\') . '%';

        $sql = "SELECT * FROM bank_transaction WHERE label LIKE ? ORDER BY date DESC, rdate DESC LIMIT ?";
        $stmt = Db::execute($sql, "si", $pattern, $limit);

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Load transactions from an array.
     *
     * @param array $data   The array of transactions
     * @param int   $userId The user owning the synchronized bank account
     * @return array The transactions that were not known yet (with their cleaned label)
     */
    public static function save($data, $userId)
    {
        $created = [];

        // Insert each transaction (duplicates are merged on their UUID)
        foreach ($data as $transaction) {
            Webservice::sendProgress($transaction['raw']);
            if (self::create($transaction, $userId)) {
                $created[] = ['label' => self::cleanLabel($transaction['raw']), 'amount' => (float)$transaction['amount']];
            }
        }

        return $created;
    }


    /**
     * Save a transaction in the database.
     *
     * @param array $transaction The transaction to save
     * @param int   $userId      The user owning the transaction
     * @return bool True if the transaction is new, false if it was already known
     */
    private static function create($transaction, $userId)
    {
        $type = $transaction['type'];
        $label = self::cleanLabel($transaction['raw']);
        $amount = $transaction['amount'];
        $rdate = $transaction['rdate'] ?? $transaction['date'];
        $card = $transaction['card'] ?? '';
        
        $date = self::fixPrelevementDate($type, $amount, $transaction['date']);
        $uuid = self::generateTransactionUuid($type, $rdate, $label, $amount);

        $sql = "INSERT INTO bank_transaction (uuid, date, rdate, amount, label, type, card, user)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE type=?, label=?";

        $stmt = Db::execute($sql, "sssdsisiis", $uuid, $date, $rdate, $amount, $label, $type, $card, $userId, $type, $label);

        // MySQL reports 1 affected row for an insert, 2 or 0 for an update of a known UUID
        return $stmt->affected_rows === 1;
    }

    /**
     * Generate a unique identifier for a transaction.
     *
     * @param int    $type   The type of the transaction
     * @param string $rdate  The real date of the transaction
     * @param string $label  The label of the transaction
     * @param float  $amount The amount of the transaction
     * @return string The UUID
     */
    private static function generateTransactionUuid($type, $rdate, $label, $amount)
    {
        // For card transactions (types 7 and 12), truncate label to 24 chars to avoid duplicates
        $labelPart = ($type == 7 || $type == 12) ? substr($label, 0, 24) : $label;
        return md5($rdate . $labelPart . $amount);
    }

    /**
     * Fix the date of a transaction.
     *
     * @param int    $type   The type of the transaction
     * @param float  $amount The amount of the transaction
     * @param string $date   The date of the transaction
     * @return string The corrected date
     */
    private static function fixPrelevementDate($type, $amount, $date)
    {
        // We change the date of the transaction if it's a Salary.
        if ($type == 1) {

            $dayOfMonth = intval(date("d", strtotime($date)));

            if ($dayOfMonth > 25) {
                // we set the date of the first day of the next month
                $date = date("Y-m-d", strtotime("first day of next month", strtotime($date)));
            }
        }

        return $date;
    }

    /**
     * Clean the label of a transaction.
     *
     * @param string $label The label to clean
     * @return string The cleaned label
     */
    public static function cleanLabel($label)
    {
        $label = strtoupper($label);
        $label = addslashes($label);
        $label = trim($label);

        // Fix the label of the transaction
        // for each $SETTINGS['regex_label'] we replace the label by the $SETTINGS['replace_label']
        foreach (Config::get('regex_label') as $regex => $replace) {

            $label = preg_replace($regex, $replace, $label);
        }

        return $label;
    }

    /**
     * Count the number of unpointed transactions of the household.
     *
     * @return int The number of unpointed transactions
     */
    public static function countUnpointed()
    {
        // Count the number of transactions with pointed=0 for the current month only
        $sql = "SELECT COUNT(*) as count FROM bank_transaction WHERE pointed=0 AND MONTH(date)=MONTH(CURDATE()) AND YEAR(date)=YEAR(CURDATE())";
        $stmt = Db::execute($sql, "");

        $result = $stmt->get_result();
        $count = $result->fetch_assoc()['count'];

        return $count;
    }   

    /**
     * Update the category of transactions without category.
     *
     * @param bool $all Whether to update all transactions or only the last month
     * @return int The number of transactions categorized
     */
    public static function updateMissingCategories($all=false)
    {
        if ($all) {
            // We update all transactions without category
            $sql = "SELECT * FROM bank_transaction WHERE category=0";
        } else {
            // We update only transactions from the last 1 month without category
            $sql = "SELECT * FROM bank_transaction WHERE category=0 AND date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
        }

        // Get the list of transactions without category
        $result = Db::query($sql);
        $updated = 0;

        // For each transaction found without category
        while ($row = $result->fetch_assoc()) {

            // Get the label
            $label = $row["label"];

            // Get the category
            $category = Category::find($label);

            // A category has been found we update the transaction
            if ($category !== null) {

                // Update the transaction
                self::updateCategory($row["id"], $category, false);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Set a category on a transaction.
     *
     * @param int  $id          The id of the transaction
     * @param int  $category    The category to set
     * @param bool $autoPointed Whether to auto-point the transaction
     * @return void
     */
    #[ApiRoute('/transaction/category', method: 'PUT')]
    public static function updateCategory($id, $category,$autoPointed=true)
    {
        // Set the category
        $sql = "UPDATE bank_transaction SET category = ? WHERE id = ?";

        $stmt = Db::execute($sql, "ii", $category, $id);
        if ($stmt) {
            if($autoPointed) {
                self::updatePointed($id);
            }
        }
    }

    /**
     * Point (or unpoint) a transaction in database.
     *
     * @param int  $id      The id of the transaction
     * @param bool $pointed True to point the transaction, false to unpoint it
     * @return void
     */
    #[ApiRoute('/transaction/pointed', method: 'PUT')]
    public static function updatePointed($id, $pointed = true)
    {
        // Update the pointed field on a transaction
        $sql = "UPDATE bank_transaction SET pointed=? WHERE id=?";
        Db::execute($sql, "ii", filter_var($pointed, FILTER_VALIDATE_BOOLEAN) ? 1 : 0, $id);
    }
}
