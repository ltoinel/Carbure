-- Origin of the category of a transaction: 'rule' (set by a rule), 'manual' (chosen by
-- hand: portal, mobile apps, split, transaction entered by hand). The rules never
-- overwrite a category chosen by hand. The existing transactions keep NULL (unknown).
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_transaction' AND COLUMN_NAME = 'category_source'

ALTER TABLE `bank_transaction`
  ADD COLUMN `category_source` varchar(10) DEFAULT NULL COMMENT 'Origin of the category: rule, manual (NULL: unknown)' AFTER `category`;
