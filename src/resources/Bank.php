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
     * Get the bank accounts of the household (every user sees all of them).
     * Each bank account is returned with bankId = account_number@bank_name
     * @return array List of bank accounts with bankId and details
     */
    #[ApiRoute('/bank', method: 'GET')]
    public static function get()
    {
        $sql = "SELECT MIN(id) AS id, account_number, bank_name FROM bank_account
                GROUP BY account_number, bank_name ORDER BY bank_name, account_number";
        $stmt = Db::execute($sql, "");
        $result = $stmt->get_result();
        
        $accounts = array();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $accounts[] = array(
                    'id' => $row['id'],
                    'bankId' => $row['account_number'] . '@' . $row['bank_name'],
                    'account_number' => $row['account_number'],
                    'bank_name' => $row['bank_name']
                );
            }
        }
        
        return $accounts;
    }
    
    /**
     * Validate a bank account identifier and woob backend name.
     *
     * @param string $accountNumber The account identifier in woob
     * @param string $bankName      The woob backend name
     * @return void
     * @throws Error If one of them is invalid
     */
    private static function validateAccount($accountNumber, $bankName)
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', (string)$accountNumber)) {
            throw new Error("Invalid account number", 400);
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', (string)$bankName)) {
            throw new Error("Invalid bank (woob backend) name", 400);
        }
    }

    /**
     * Get a bank account of the household.
     *
     * @param int $id The account id
     * @return array The account
     * @throws Error If the account does not exist
     */
    private static function existingAccount($id)
    {
        $account = Db::queryOne("SELECT * FROM bank_account WHERE id = ?", "i", $id);
        if (!$account) {
            throw new Error("Account not found", 404);
        }
        return $account;
    }

    /**
     * All the bank accounts of the household with the user who added them and the
     * result of their last synchronization (administrators).
     *
     * @return array The accounts: id, bankId, account_number, bank_name, user_id and
     *               username (who added it),
     *               last_sync_at, last_sync_status (OK|ERROR), last_sync_message
     */
    #[ApiRoute('/bank/accounts', method: 'GET')]
    public static function accounts()
    {
        User::requireAdmin();
        $sql = "SELECT a.id, a.account_number, a.bank_name, a.user_id, u.username,
                       a.last_sync_at, a.last_sync_status, a.last_sync_message
                FROM bank_account a JOIN users u ON u.id = a.user_id
                ORDER BY a.bank_name, a.account_number, u.username";
        $accounts = [];
        foreach (Db::execute($sql, "")->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $accounts[] = ['id' => $row['id'], 'bankId' => $row['account_number'] . '@' . $row['bank_name']] + $row;
        }
        return $accounts;
    }

    /**
     * Follow a bank account (it is synchronized from now on).
     *
     * @param string $account_number The account identifier in woob
     * @param string $bank_name      The woob backend name
     * @return array The account (user_id: the administrator who added it)
     * @throws Error If invalid or already followed by the household
     */
    #[ApiRoute('/bank', method: 'POST')]
    public static function create($account_number, $bank_name)
    {
        User::requireAdmin();
        self::validateAccount($account_number, $bank_name);
        $userId = (int)Jwt::getUserIdFromToken();

        if (Db::queryOne("SELECT id FROM bank_account WHERE account_number = ? AND bank_name = ?", "ss", $account_number, $bank_name)) {
            throw new Error("This account is already followed", 409);
        }

        $stmt = Db::execute("INSERT INTO bank_account (bank_name, account_number, user_id) VALUES (?, ?, ?)", "ssi", $bank_name, $account_number, $userId);

        return ['id' => $stmt->insert_id, 'bankId' => "$account_number@$bank_name", 'account_number' => $account_number, 'bank_name' => $bank_name, 'user_id' => $userId];
    }

    /**
     * Modify a followed bank account.
     *
     * @param int    $id             The account id
     * @param string $account_number The account identifier in woob
     * @param string $bank_name      The woob backend name
     * @return array The account
     * @throws Error If not found, invalid or already followed by the household
     */
    #[ApiRoute('/bank', method: 'PUT')]
    public static function update($id, $account_number, $bank_name)
    {
        User::requireAdmin();
        $account = self::existingAccount($id);
        self::validateAccount($account_number, $bank_name);
        $userId = (int)$account['user_id'];

        if (Db::queryOne("SELECT id FROM bank_account WHERE account_number = ? AND bank_name = ? AND id <> ?", "ssi", $account_number, $bank_name, $id)) {
            throw new Error("This account is already followed", 409);
        }

        Db::execute("UPDATE bank_account SET account_number = ?, bank_name = ? WHERE id = ?", "ssi", $account_number, $bank_name, $id);

        return ['id' => (int)$id, 'bankId' => "$account_number@$bank_name", 'account_number' => $account_number, 'bank_name' => $bank_name, 'user_id' => $userId];
    }

    /**
     * Stop following a bank account (its transactions are kept).
     *
     * @param int $id The account id
     * @return bool True if deleted
     * @throws Error If not found
     */
    #[ApiRoute('/bank', method: 'DELETE')]
    public static function delete($id)
    {
        User::requireAdmin();
        self::existingAccount($id);
        Db::execute("DELETE FROM bank_account WHERE id = ?", "i", $id);
        return true;
    }

    /**
     * Bank backends configured in woob (name and module), to choose the bank of an account.
     *
     * @return array The backends: [{name, module}]
     */
    #[ApiRoute('/bank/backends', method: 'GET')]
    public static function backends()
    {
        User::requireAdmin();
        return Woob::listBackends();
    }

    /**
     * Banks supported by woob (module and description); a bank must be configured
     * in woob (php tools/carbure.php add-bank) before its accounts can be synchronized.
     *
     * @return array The modules: [{module, description}]
     */
    #[ApiRoute('/bank/modules', method: 'GET')]
    public static function modules()
    {
        User::requireAdmin();
        return Woob::listBankModules();
    }

    /**
     * Settings asked by a woob bank module, to configure the bank from the portal
     * (administrators).
     *
     * @param string $module The woob module (e.g. bnp)
     * @return array module, description, fields [{key, label, description, default, required, masked, choices}]
     * @throws Error If the module name is invalid
     */
    #[ApiRoute('/bank/module', method: 'GET')]
    public static function module($module)
    {
        User::requireAdmin();
        if (!preg_match('/^[a-z0-9_]{1,50}$/', (string)$module)) {
            throw new Error("Invalid woob module", 400);
        }
        return Woob::moduleFields($module);
    }

    /**
     * Configure a bank in woob from the portal (administrators): the credentials
     * are given to woob, which keeps them; Carbure stores and logs nothing of them.
     * The accounts woob then finds are returned, to follow them.
     *
     * @param string $module   The woob module (e.g. bnp)
     * @param string $backend  Name of the woob backend (e.g. bnp, bnp_pro)
     * @param array  $settings The settings of the module (login, password...)
     * @return array backend, accounts [{bankId, account_number, bank_name, label, balance, currency, followed}]
     * @throws Error If a setting is missing or invalid, or woob refuses the bank
     */
    #[ApiRoute('/bank/backend', method: 'POST')]
    public static function createBackend($module, $backend, $settings = [])
    {
        User::requireAdmin();
        if (!preg_match('/^[a-z0-9_]{1,50}$/', (string)$module) || !preg_match('/^[a-z0-9_-]{1,50}$/', (string)$backend)) {
            throw new Error("Invalid woob module or bank name (lowercase letters, digits, - and _)", 400);
        }
        if (in_array($backend, array_column(Woob::listBackends(), 'name'), true)) {
            throw new Error("A bank named $backend is already configured in woob", 409);
        }

        // Only the settings of the module, required ones present, values on one line
        $params = [];
        foreach (Woob::moduleFields($module)['fields'] as $field) {
            $value = is_array($settings) ? trim((string)($settings[$field['key']] ?? '')) : '';
            if ($value === '') {
                if ($field['required'] && $field['default'] === '') {
                    throw new Error("Missing setting: " . $field['label'], 400);
                }
                continue;
            }
            if (preg_match('/[\x00-\x1f\x7f]/', $value) || preg_match('/\s/', $value) || mb_strlen($value) > 200) {
                throw new Error("Invalid value for " . $field['label'] . " (no spaces)", 400);
            }
            if ($field['choices'] && !in_array($value, array_column($field['choices'], 'value'), true)) {
                throw new Error("Invalid choice for " . $field['label'], 400);
            }
            $params[$field['key']] = $value;
        }

        try {
            Woob::addBackend($module, $backend, $params);
        } catch (Exception $e) {
            throw new Error($e->getMessage(), 400);
        }

        // Accounts of the new bank, to follow them
        $accounts = array_values(array_filter(self::discover(), fn($account) => $account['bank_name'] === $backend));
        return ['backend' => $backend, 'accounts' => $accounts];
    }

    /**
     * Accounts available in the configured woob backends, to follow them in one click.
     *
     * @return array The accounts (bankId, account_number, bank_name, label, balance, currency, followed)
     * @throws Exception If woob fails
     */
    #[ApiRoute('/bank/discover', method: 'GET')]
    public static function discover()
    {
        User::requireAdmin();
        $followed = array_column(self::accounts(), 'bankId');

        $accounts = [];
        foreach (Woob::listAccounts() as $account) {
            $at = strrpos($account['id'], '@');
            if ($at === false) {
                continue;
            }
            $bankId = $account['id'];
            $accounts[] = [
                'bankId' => $bankId,
                'account_number' => substr($bankId, 0, $at),
                'bank_name' => substr($bankId, $at + 1),
                'label' => $account['label'] ?? null,
                'balance' => $account['balance'] ?? null,
                'currency' => $account['currency'] ?? null,
                'followed' => in_array($bankId, $followed, true),
            ];
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
            Webservice::sendProgress("Done $bankId (coming): 0 received, 0 new");
            return [];
        }

        // We remove all the transactions with a type != 12
        $transactions = array_filter($transactions, function ($transaction) {
            return $transaction['type'] == 12;
        });

        $created = Transaction::save($transactions, self::getBankOwner($bankId));
        Webservice::sendProgress("Done $bankId (coming): " . count($transactions) . " received, " . count($created) . " new");
        return $created;
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
            Webservice::sendProgress("Done $bankId (history): 0 received, 0 new");
            return [];
        }

        $created = Transaction::save($transactions, self::getBankOwner($bankId));
        Webservice::sendProgress("Done $bankId (history): " . count($transactions) . " received, " . count($created) . " new");
        return $created;
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
     * @param string|null $token   The sync token (for the scheduler)
     * @param string|null $account Synchronize only this account (account_number@bank_name)
     * @return void
     * @throws Error If unauthorized or the account is not followed
     */
    #[ApiRoute('/bank/sync', method: 'GET', public: true, stream: true)]
    public static function getSync($token = null, $account = null)
    {
        self::checkSyncAccess($token);

        $bankIds = self::getBankIds();
        if ($account !== null && $account !== '') {
            if (!in_array($account, $bankIds, true)) {
                throw new Error("Account not found", 404);
            }
            $bankIds = [$account];
        }

        // Allow unlimited execution time for bank sync
        set_time_limit(0);

        // Acquire exclusive lock to prevent concurrent syncs
        $lockAcquired = Db::query("SELECT GET_LOCK('bank_sync', 0) AS locked")->fetch_assoc()['locked'];
        if (!$lockAcquired) {
            Webservice::sendProgress("Synchronization already in progress");
            return;
        }

        try {
            // Synchronize each bank account
            foreach ($bankIds as $bankId) {
                
                $status = true;
                $created = [];
                $error = null;

                try {
                    Webservice::sendProgress("Syncing $bankId (coming)...");
                    $created = self::syncBankComing($bankId);

                    Webservice::sendProgress("Syncing $bankId (history)...");
                    $created = array_merge($created, self::syncBankHistory($bankId));

                } catch (Exception $e) {
                    Logger::error("Error syncing $bankId: " . $e->getMessage());
                    Webservice::sendProgress("Error syncing $bankId: " . $e->getMessage());
                    $status = false;
                    $error = $e->getMessage();
                }
                self::recordSyncResult($bankId, $status, $status ? count($created) . " new" : $error);

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
    /**
     * Store the date and result of the last synchronization of an account.
     *
     * @param string      $bankId  The account (account_number@bank_name)
     * @param bool        $success True if the synchronization succeeded
     * @param string|null $message The number of new transactions or the error
     * @return void
     */
    private static function recordSyncResult($bankId, $success, $message)
    {
        [$number, $bank] = explode('@', $bankId, 2);
        try {
            Db::execute("UPDATE bank_account SET last_sync_at = NOW(), last_sync_status = ?, last_sync_message = ? WHERE account_number = ? AND bank_name = ?",
                "ssss", $success ? 'OK' : 'ERROR', mb_substr((string)$message, 0, 500), $number, $bank);
        } catch (Throwable $e) {
            // Database not migrated yet (sql/migrations/2026-10-06_sync_status.sql): the sync goes on
            Logger::error("Unable to record the sync result of $bankId: " . $e->getMessage());
        }
    }

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
        
        // The accounts are shared by the household: every user is notified
        $stmt = Db::execute("SELECT id AS user_id FROM users", "");
        $result = $stmt->get_result();
        
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
        // The accounts are shared by the household: every user with a threshold is alerted
        $sql = "SELECT id, language, alert_threshold FROM users WHERE alert_threshold > 0";
        $users = Db::execute($sql, "")->get_result()->fetch_all(MYSQLI_ASSOC);

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
