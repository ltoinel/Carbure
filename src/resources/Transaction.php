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

namespace ltoinel\resources;

use ltoinel\lib\ApiRoute;
use ltoinel\lib\Db;
use ltoinel\lib\Jwt;
use ltoinel\lib\Month;

require_once "Category.php";

final class Transaction {

    /** Days between a transaction of a file and one of the database for a probable duplicate */
    const DUPLICATE_DAYS = 3;
    
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
        [$from, $to] = Month::range($month, $year);

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
     * Months that have transactions, newest first: the periods offered by the portal.
     *
     * @return array The periods: [{year, month}]
     */
    #[ApiRoute('/transaction/periods', method: 'GET')]
    public static function periods()
    {
        return Db::query("SELECT DISTINCT YEAR(date) AS year, MONTH(date) AS month FROM bank_transaction ORDER BY year DESC, month DESC")
            ->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Load transactions from an array.
     *
     * @param array $data     The array of transactions
     * @param int   $userId   The user owning the synchronized bank account
     * @param bool  $progress Send each label as a progress event (synchronization)
     * @param array $origin   Account the transactions come from: bank_name (woob backend of
     *                        a synchronized account), account_number (synchronized or imported)
     * @return array The transactions that were not known yet (label cleaned, amount, uuid)
     */
    public static function save($data, $userId, $progress = true, $origin = [])
    {
        $created = [];

        // Insert each transaction (duplicates are merged on their UUID)
        foreach ($data as $transaction) {
            if ($progress) {
                Webservice::sendProgress($transaction['raw']);
            }
            $row = self::prepare($transaction);
            if (self::create($row, $userId, $origin)) {
                $created[] = ['label' => $row['label'], 'amount' => (float)$transaction['amount'], 'uuid' => $row['uuid']];
            }
        }

        return $created;
    }

    /**
     * Columns of a transaction as it is stored: cleaned label, date of the salaries
     * moved to the next month, UUID.
     *
     * @param array $transaction The transaction {type, raw, amount, date, rdate?, card?}
     * @return array uuid, date, rdate, amount, label, type, card
     */
    public static function prepare($transaction)
    {
        $type = $transaction['type'];
        $label = self::cleanLabel($transaction['raw']);
        $amount = $transaction['amount'];
        $rdate = $transaction['rdate'] ?? $transaction['date'];

        return [
            'uuid' => self::generateTransactionUuid($type, $rdate, $label, $amount),
            'date' => self::fixPrelevementDate($type, $amount, $transaction['date']),
            'rdate' => $rdate,
            'amount' => $amount,
            'label' => $label,
            'type' => $type,
            'card' => $transaction['card'] ?? '',
        ];
    }


    /**
     * Save a transaction in the database.
     *
     * @param array $row    The transaction, as returned by prepare()
     * @param int   $userId The user owning the transaction
     * @param array $origin bank_name and account_number of the account it comes from
     * @return bool True if the transaction is new, false if it was already known
     */
    private static function create($row, $userId, $origin = [])
    {
        ['uuid' => $uuid, 'date' => $date, 'rdate' => $rdate, 'amount' => $amount,
         'label' => $label, 'type' => $type, 'card' => $card] = $row;

        // A known transaction keeps its origin; one saved before it was stored gets it
        $sql = "INSERT INTO bank_transaction (uuid, date, rdate, amount, label, type, card, bank_name, account_number, user)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE type=?, label=?,
                    bank_name = IFNULL(bank_name, VALUES(bank_name)),
                    account_number = IFNULL(account_number, VALUES(account_number))";

        $stmt = Db::execute($sql, "sssdsisssiis", $uuid, $date, $rdate, $amount, $label, $type, $card,
            $origin['bank_name'] ?? null, $origin['account_number'] ?? null, $userId, $type, $label);

        // MySQL reports 1 affected row for an insert, 2 or 0 for an update of a known UUID
        return $stmt->affected_rows === 1;
    }

    /**
     * Read a statement file downloaded from a bank (OFX/QFX, QIF, CAMT.053, CSV) and
     * tell, for each transaction, whether it would be new: "known" (same UUID, it would
     * be merged), "duplicate" (a transaction of the same amount a few days apart, most
     * likely the same one synchronized with another label) or "new".
     *
     * @param string      $file     The content of the file, base64 encoded
     * @param string|null $filename The name of the file (helps to tell the format)
     * @return array format, account, from, to, counts {new, known, duplicate} and rows
     *               [{index, date, rdate, amount, label, type, status, match?}]
     * @throws Error If the file is not understood
     */
    #[ApiRoute('/transaction/import/preview', method: 'POST')]
    public static function previewImport($file, $filename = null)
    {
        [$parsed, $rows] = self::analyzeImport($file, $filename);
        $counts = ['new' => 0, 'known' => 0, 'duplicate' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']]++;
        }
        $dates = array_column($rows, 'date');

        return [
            'format' => $parsed['format'],
            'account' => $parsed['account'],
            'from' => min($dates),
            'to' => max($dates),
            'counts' => $counts,
            'rows' => $rows,
        ];
    }

    /**
     * Import a statement file: its transactions are saved for the current user, then
     * categorized with the rules. The known ones are skipped; the probable duplicates
     * only when they are not selected.
     *
     * @param string      $file     The content of the file, base64 encoded
     * @param string|null $filename The name of the file
     * @param array|null  $selected Indexes of the rows to import (by default: the new ones)
     * @return array imported (number of new transactions), categorized, from, to
     * @throws Error If the file is not understood
     */
    #[ApiRoute('/transaction/import', method: 'POST')]
    public static function import($file, $filename = null, $selected = null)
    {
        [$parsed, $rows] = self::analyzeImport($file, $filename);
        $selected = $selected === null ? null : array_map('intval', (array)$selected);

        $data = [];
        foreach ($rows as $row) {
            $wanted = $selected === null ? $row['status'] === 'new' : in_array($row['index'], $selected, true);
            if ($wanted && $row['status'] !== 'known') {
                $data[] = $parsed['transactions'][$row['index']];
            }
        }

        $created = self::save($data, (int)Jwt::getUserIdFromToken(), false, ['account_number' => $parsed['account']]);

        // The rules categorize the new transactions, whatever their date
        $categorized = 0;
        $keywords = Category::loadKeywords();
        foreach ($created as $transaction) {
            $category = Category::find($transaction['label'], $keywords);
            if ($category !== null) {
                Db::execute("UPDATE bank_transaction SET category = ? WHERE uuid = ? AND category = 0", "is", $category, $transaction['uuid']);
                $categorized++;
            }
        }
        Logger::info("Import of a " . $parsed['format'] . " file: " . count($created) . " new transaction(s), $categorized categorized");

        $dates = array_column($data, 'date');
        return [
            'imported' => count($created),
            'categorized' => $categorized,
            'from' => $dates ? min($dates) : null,
            'to' => $dates ? max($dates) : null,
        ];
    }

    /**
     * Parse a statement file and compare its transactions with the database.
     *
     * @param string      $file     The content of the file, base64 encoded
     * @param string|null $filename The name of the file
     * @return array [parsed file (BankFile::parse), rows with their status]
     * @throws Error If the file is not understood
     */
    private static function analyzeImport($file, $filename)
    {
        $content = base64_decode((string)$file, true);
        if ($content === false) {
            throw new Error("The file must be base64 encoded", 400);
        }
        $parsed = BankFile::parse($content, $filename);

        $rows = [];
        foreach ($parsed['transactions'] as $index => $transaction) {
            $row = self::prepare($transaction);
            $rows[] = ['index' => $index, 'date' => $row['date'], 'rdate' => $row['rdate'], 'amount' => $row['amount'],
                       'label' => stripslashes($row['label']), 'type' => $row['type'], 'uuid' => $row['uuid'], 'status' => 'new'];
        }

        // Transactions of the period of the file (and a few days around)
        $dates = array_merge(array_column($rows, 'date'), array_column($rows, 'rdate'));
        $from = date('Y-m-d', strtotime(min($dates) . ' -' . self::DUPLICATE_DAYS . ' days'));
        $to = date('Y-m-d', strtotime(max($dates) . ' +' . self::DUPLICATE_DAYS . ' days'));
        $existing = Db::execute("SELECT id, uuid, date, rdate, amount, label FROM bank_transaction WHERE date BETWEEN ? AND ? OR rdate BETWEEN ? AND ?",
            "ssss", $from, $to, $from, $to)->get_result()->fetch_all(MYSQLI_ASSOC);
        $uuids = array_flip(array_column($existing, 'uuid'));

        $seen = [];
        $matched = [];
        foreach ($rows as &$row) {
            // Same UUID: in the database, or twice in the file
            if (isset($uuids[$row['uuid']]) || isset($seen[$row['uuid']])) {
                $row['status'] = 'known';
                if (isset($uuids[$row['uuid']])) {
                    $matched[$existing[$uuids[$row['uuid']]]['id']] = true;
                }
            }
            $seen[$row['uuid']] = true;
        }
        unset($row);
        foreach ($rows as &$row) {
            if ($row['status'] !== 'new') {
                continue;
            }
            // Same amount, a few days apart: each transaction of the database matches one row at most
            foreach ($existing as $transaction) {
                if (isset($matched[$transaction['id']]) || abs((float)$transaction['amount'] - (float)$row['amount']) >= 0.005) {
                    continue;
                }
                $gap = min(self::daysBetween($transaction['date'], $row['date']), self::daysBetween($transaction['rdate'], $row['rdate']));
                if ($gap <= self::DUPLICATE_DAYS) {
                    $row['status'] = 'duplicate';
                    $row['match'] = ['id' => (int)$transaction['id'], 'date' => $transaction['date'], 'label' => $transaction['label']];
                    $matched[$transaction['id']] = true;
                    break;
                }
            }
        }
        unset($row);

        foreach ($rows as &$row) {
            unset($row['uuid']);
        }
        unset($row);
        return [$parsed, $rows];
    }

    /**
     * Number of days between two dates.
     *
     * @param string|null $a A date (Y-m-d)
     * @param string|null $b Another date
     * @return int The number of days (a large number if a date is missing)
     */
    private static function daysBetween($a, $b)
    {
        if (!$a || !$b) {
            return PHP_INT_MAX;
        }
        return (int)abs((strtotime($a) - strtotime($b)) / 86400);
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
        $sql = "SELECT COUNT(*) as count FROM bank_transaction WHERE pointed=0 AND date >= ? AND date < ?";
        $stmt = Db::execute($sql, "ss", ...Month::range());

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

        // The rules are loaded once for all the transactions
        $keywords = Category::loadKeywords();

        // For each transaction found without category
        while ($row = $result->fetch_assoc()) {

            // Get the label
            $label = $row["label"];

            // Get the category
            $category = Category::find($label, $keywords);

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
