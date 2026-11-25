-- The same transaction on two accounts of the household (#528). The UUID of a transaction,
-- md5(real date, label, amount), has no account and was unique for the whole household:
-- account fees or a subscription charged the same day on two accounts were saved once, on
-- the first account synchronized. The UUID is now unique on its account number (the
-- transactions without account are told apart by Transaction::known(): a unique key
-- ignores the NULL values). No UUID is changed, no row is rewritten. A deleted transaction
-- is remembered with its account as well; those deleted before have none (empty): they
-- stay deleted on every account.
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_transaction_deleted' AND COLUMN_NAME = 'account_number'

ALTER TABLE `bank_transaction`
  DROP INDEX `unique_uuid`,
  ADD UNIQUE KEY `unique_uuid_account` (`uuid`, `account_number`);

ALTER TABLE `bank_transaction_deleted`
  ADD COLUMN `account_number` varchar(100) NOT NULL DEFAULT '' COMMENT 'Account number of the deleted transaction (empty: unknown, every account)' AFTER `uuid`,
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (`uuid`, `account_number`);
