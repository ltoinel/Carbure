-- Name given to a followed bank account (e.g. "Compte joint"), shown instead of
-- "BANK ···1234" when set. NULL for the accounts followed before.
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_account' AND COLUMN_NAME = 'label'

ALTER TABLE `bank_account`
  ADD COLUMN `label` varchar(100) DEFAULT NULL COMMENT 'Name given to the account' AFTER `account_number`;
