-- Migration 2026-10-04 : alert threshold per user, index on transaction dates
-- To apply once on the existing database BEFORE deploying the code.
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'alert_threshold'

-- Push alert when a new unchecked expense exceeds this amount (NULL = disabled)
ALTER TABLE `users` ADD COLUMN `alert_threshold` decimal(10,2) DEFAULT NULL AFTER `language`;

-- Month views, budgets and trends filter the transactions by date
ALTER TABLE `bank_transaction` ADD KEY `transaction_date` (`date`);
