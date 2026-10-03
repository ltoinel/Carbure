-- Migration 2026-10-05 : amounts in DECIMAL, utf8mb4, unique bank accounts
-- Can be applied at any time: the code does not depend on it.
-- applied-if: SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_account' AND INDEX_NAME = 'unique_account_user'

-- Budgets are amounts of money: exact decimals instead of floating point
ALTER TABLE `budget` MODIFY `amount` decimal(10,2) NOT NULL;

-- Full Unicode (utf8 is limited to 3 bytes per character in MySQL/MariaDB)
ALTER TABLE `bank_account` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `bank_transaction` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `bank_transaction_category` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `bank_transaction_category_keyword` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `budget` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `budget_insight` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `devices` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `users` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- A bank account is linked once to a user: remove the duplicates, then enforce it
DELETE a FROM `bank_account` a
  JOIN `bank_account` b ON a.account_number = b.account_number AND a.bank_name = b.bank_name
   AND a.user_id = b.user_id AND a.id > b.id;
ALTER TABLE `bank_account` ADD UNIQUE KEY `unique_account_user` (`account_number`, `bank_name`, `user_id`);
