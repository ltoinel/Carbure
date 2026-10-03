-- Date and result of the last synchronization of each bank account (Accounts tab).
-- Applied automatically by tools/migrate.php (Docker: at each start).
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_account' AND COLUMN_NAME = 'last_sync_status'

ALTER TABLE `bank_account`
  ADD COLUMN `last_sync_at` datetime DEFAULT NULL,
  ADD COLUMN `last_sync_status` enum('OK','ERROR') DEFAULT NULL,
  ADD COLUMN `last_sync_message` varchar(500) DEFAULT NULL;
