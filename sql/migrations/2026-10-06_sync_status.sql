-- Date and result of the last synchronization of each bank account (Accounts tab).
-- Apply BEFORE deploying the code that reads these columns.

ALTER TABLE `bank_account`
  ADD COLUMN `last_sync_at` datetime DEFAULT NULL,
  ADD COLUMN `last_sync_status` enum('OK','ERROR') DEFAULT NULL,
  ADD COLUMN `last_sync_message` varchar(500) DEFAULT NULL;
