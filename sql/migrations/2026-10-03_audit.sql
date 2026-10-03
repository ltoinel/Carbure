-- Migration 2026-10-03 : roles, device tokens, safer foreign key
-- To apply once on the existing database before deploying the code.

START TRANSACTION;

-- Administrator role (user 1 becomes administrator)
ALTER TABLE `users` ADD COLUMN `is_admin` tinyint(1) NOT NULL DEFAULT 0 AFTER `email`;
UPDATE `users` SET `is_admin` = 1 WHERE `id` = 1;

-- User interface language (portal profile page)
ALTER TABLE `users` ADD COLUMN `language` varchar(5) NOT NULL DEFAULT 'fr' AFTER `is_admin`;

-- APNs device tokens are 64 hex chars (varchar(50) was truncating them)
ALTER TABLE `devices` MODIFY `token` varchar(200) NOT NULL;

-- Deleting a user must not delete the household bank transactions
ALTER TABLE `bank_transaction` DROP FOREIGN KEY `fk_transaction_user`;
ALTER TABLE `bank_transaction`
  ADD CONSTRAINT `fk_transaction_user` FOREIGN KEY (`user`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

COMMIT;
