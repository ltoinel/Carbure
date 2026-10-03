-- Notify the household when a new transaction matches a categorization rule.
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_transaction_category_keyword' AND COLUMN_NAME = 'notify'

ALTER TABLE `bank_transaction_category_keyword`
  ADD COLUMN `notify` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Push to the household when a new transaction matches';
