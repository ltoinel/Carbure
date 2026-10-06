-- Bank at the origin of a transaction, shown in its detail: the bank (woob backend) and
-- the account number of the synchronized account, or the account number of an imported
-- statement file. NULL for the transactions saved before: a synchronization fills them
-- when it receives them again.
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_transaction' AND COLUMN_NAME = 'bank_name'

ALTER TABLE `bank_transaction`
  ADD COLUMN `bank_name` varchar(50) DEFAULT NULL COMMENT 'Bank (woob backend) of the synchronized account' AFTER `card`,
  ADD COLUMN `account_number` varchar(100) DEFAULT NULL COMMENT 'Number of the synchronized or imported account' AFTER `bank_name`;
