-- Split of a transaction across several categories: each part is a transaction of its
-- own (same date, label and account), whose split_of is the id of the transaction it was
-- taken from; that one keeps the rest of the amount. Deleting it deletes its parts.
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_transaction' AND COLUMN_NAME = 'split_of'

ALTER TABLE `bank_transaction`
  ADD COLUMN `split_of` bigint(20) unsigned DEFAULT NULL COMMENT 'Transaction this part was split from (NULL: not a part)' AFTER `id`,
  ADD KEY `transaction_split_of` (`split_of`),
  ADD CONSTRAINT `fk_transaction_split_of` FOREIGN KEY (`split_of`) REFERENCES `bank_transaction` (`id`) ON DELETE CASCADE;
