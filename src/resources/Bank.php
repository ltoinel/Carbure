<?php

/**
 * Bank.php
 *
 * Bank class to sync transactions from a bank
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

require_once "Transaction.php";

final class Bank {
    
    /**
     * Get the list of bank accounts for the authenticated user.
     * Each bank account is returned with bankId = account_number@bank_name
     * @return array List of bank accounts with bankId and details
     */
    #[ApiRoute('/bank', method: 'GET')]
    public static function get()
    {
        $userId = Jwt::getUserIdFromToken();
        
        $sql = "SELECT DISTINCT account_number, bank_name FROM bank_account WHERE user_id = ? ORDER BY bank_name, account_number";
        $stmt = Db::execute($sql, "i", $userId);
        $result = $stmt->get_result();
        
        $accounts = array();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $accounts[] = array(
                    'bankId' => $row['account_number'] . '@' . $row['bank_name'],
                    'account_number' => $row['account_number'],
                    'bank_name' => $row['bank_name']
                );
            }
        }
        
        return $accounts;
    }
    
    /**
     * Get the list of coming transactions from a bank.
     *
     * @param string $bankId The bank id to sync
     * @return array The new transactions
     */
    private static function syncBankComing($bankId)
    {
        $transactions = self::getBankData("coming", $bankId);

        if (empty($transactions)) {
            Logger::warn("No coming transactions for $bankId");
            return [];
        }

        // We remove all the transactions with a type != 12
        $transactions = array_filter($transactions, function ($transaction) {
            return $transaction['type'] == 12;
        });

        return Transaction::save($transactions, self::getBankOwner($bankId));
    }

    /**
     * Get the list of history transactions from a bank.
     *
     * @param string $bankId The bank id to sync
     * @return array The new transactions
     */
    private static function syncBankHistory($bankId)
    {
        $transactions = self::getBankData("history", $bankId);

        if (empty($transactions)) {
            Logger::warn("No history transactions for $bankId");
            return [];
        }

        return Transaction::save($transactions, self::getBankOwner($bankId));
    }

    /**
     * Check that the caller may start a synchronization:
     * either an authenticated user (JWT) or the scheduler with the sync_token
     * (X-Sync-Token header or ?token= parameter).
     *
     * @param string|null $token The sync token passed as query parameter
     * @return void
     * @throws Error If the caller is not allowed
     */
    private static function checkSyncAccess($token)
    {
        $token = Webservice::getHeader('X-Sync-Token') ?? $token;

        if ($token !== null && Config::has('sync_token') && hash_equals((string)Config::get('sync_token'), (string)$token)) {
            return;
        }

        if (Jwt::checkAuthorization()) {
            return;
        }

        throw new Error("Unauthorized - Invalid or missing JWT token or sync token", 401);
    }

    /**
     * Synchronize the bank data with Server-Sent Events progress.
     *
     * @param string|null $token The sync token (for the scheduler)
     * @return void
     */
    #[ApiRoute('/bank/sync', method: 'GET', public: true, stream: true)]
    public static function getSync($token = null)
    {
        self::checkSyncAccess($token);

        // Allow unlimited execution time for bank sync
        set_time_limit(0);

        // Acquire exclusive lock to prevent concurrent syncs
        $lockAcquired = Db::query("SELECT GET_LOCK('bank_sync', 0) AS locked")->fetch_assoc()['locked'];
        if (!$lockAcquired) {
            Webservice::sendProgress("Synchronization already in progress");
            return;
        }

        try {
            // Get all bank accounts
            $bankIds = self::getBankIds();

            // Synchronize each bank account
            foreach ($bankIds as $bankId) {
                
                $status = true;
                $created = [];

                try {
                    Webservice::sendProgress("Syncing $bankId (coming)...");
                    $created = self::syncBankComing($bankId);

                    Webservice::sendProgress("Syncing $bankId (history)...");
                    $created = array_merge($created, self::syncBankHistory($bankId));

                } catch (Exception $e) {
                    Logger::error("Error syncing $bankId: " . $e->getMessage());
                    Webservice::sendProgress("Error syncing $bankId: " . $e->getMessage());
                    $status = false;
                }

                // Send notification to all users who own this bank account
                Webservice::sendProgress("Notifying users of $bankId...");
                self::notifyBankUsers($bankId, $status);
                self::alertLargeExpenses($bankId, $created);
            }
            
            // Update missing categories
            Webservice::sendProgress("Updating missing categories...");
            Transaction::updateMissingCategories();
            
            // Send final completion event
            Webservice::sendProgress("Synchronization complete");
        } finally {
            Db::query("SELECT RELEASE_LOCK('bank_sync')");
        }
    }
    

    /**
     * Get the user owning a bank account (the first one if the account is shared).
     *
     * @param string $bankId The bank ID (account_number@bank_name)
     * @return int The user id
     * @throws Exception If the bank account has no owner
     */
    private static function getBankOwner($bankId)
    {
        list($accountNumber, $bankName) = explode('@', $bankId, 2);

        $sql = "SELECT MIN(user_id) AS user_id FROM bank_account WHERE account_number = ? AND bank_name = ?";
        $row = Db::queryOne($sql, "ss", $accountNumber, $bankName);

        if (!$row || $row['user_id'] === null) {
            throw new Exception("No owner found for bank account $bankId");
        }

        return (int)$row['user_id'];
    }

    /**
     * Get all bank IDs from the database.
     * @return array List of bankIds (account_number@bank_name)
     */
    private static function getBankIds()
    {
        $sql = "SELECT DISTINCT account_number, bank_name FROM bank_account ORDER BY bank_name, account_number";
        $stmt = Db::execute($sql, "");
        $result = $stmt->get_result();
        
        $bankIds = array();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $bankIds[] = $row['account_number'] . '@' . $row['bank_name'];
            }
        }
        
        return $bankIds;
    }

    /**
     * Send notification to all users who own a specific bank account.
     *
     * @param string $bankId The bank ID (account_number@bank_name)
     * @param bool   $status The sync status (true=success, false=failure)
     * @return void
     */
        public static function notifyBankUsers($bankId, $status)
    {
        // Parse bankId to extract account_number and bank_name
        list($accountNumber, $bankName) = explode('@', $bankId, 2);
        
        // Get all users who own this bank account
        $sql = "SELECT DISTINCT user_id FROM bank_account WHERE account_number = ? AND bank_name = ?";
        $stmt = Db::execute($sql, "ss", $accountNumber, $bankName);
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            Logger::warn("No users found for bank account: $bankId");
            return;
        }
        
        // Send notification to each user
        while ($row = $result->fetch_assoc()) {
            $userId = $row['user_id'];
            
            try {
                // Count unpointed transactions of the household
                $unpointed = Transaction::countUnpointed();

                // Determine notification message based on sync status
                if ($status === false) {
                    $message = "Bank synchronization failed !";
                    $description = "$unpointed transactions to review";
                } else {
                    $message = "Bank synchronization successful";
                    $description = "$unpointed transactions to review";
                }
                
                // Send notification to user
                Device::sendNotification(
                    $userId,
                    $message,
                    $description,
                    $unpointed
                );

                Logger::info("Notification sent to user $userId for bank $bankId with $unpointed unpointed transactions");
            } catch (Exception $e) {
                Logger::warn("Failed to send notification to user $userId: " . $e->getMessage());
            }
        }
    }

    /**
     * Push an alert to the owners of a bank account for each new expense whose
     * amount reaches their alert threshold (users.alert_threshold, NULL = disabled).
     *
     * @param string $bankId       The bank ID (account_number@bank_name)
     * @param array  $transactions The new transactions (label, amount)
     * @return int The number of alerts sent
     */
    public static function alertLargeExpenses($bankId, $transactions)
    {
        if (empty($transactions)) {
            return 0;
        }

        list($accountNumber, $bankName) = explode('@', $bankId, 2);
        $sql = "SELECT DISTINCT u.id, u.language, u.alert_threshold FROM users u
                JOIN bank_account a ON a.user_id = u.id
                WHERE a.account_number = ? AND a.bank_name = ? AND u.alert_threshold > 0";
        $users = Db::execute($sql, "ss", $accountNumber, $bankName)->get_result()->fetch_all(MYSQLI_ASSOC);

        $sent = 0;
        foreach ($users as $user) {
            foreach ($transactions as $transaction) {
                if ($transaction['amount'] >= 0 || -$transaction['amount'] < (float)$user['alert_threshold']) {
                    continue;
                }

                $french = $user['language'] === 'fr';
                $amount = number_format($transaction['amount'], 2, $french ? ',' : '.', $french ? ' ' : ',');
                Device::sendNotification(
                    $user['id'],
                    $french ? "Dépense importante à vérifier" : "Large expense to check",
                    trim($transaction['label']) . " : $amount €",
                    Transaction::countUnpointed()
                );
                $sent++;
            }
        }

        if ($sent > 0) {
            Logger::info("$sent large expense alert(s) sent for $bankId");
        }
        return $sent;
    }

    /**
     * Call Woob to get the list of transactions from a bank.
     *
     * @param string $type   The type of transactions to get (coming or history)
     * @param string $bankId The bank id
     * @return array|null The transaction data or null if no data
     */
    private static function getBankData($type, $bankId)
    {
        return Woob::getBankData($type, $bankId);
    }
}
