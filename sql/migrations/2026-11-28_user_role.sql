-- Role of a user (#539): admin, member (reads and writes the household data) or viewer
-- (read-only member: an accountant, a teenager). is_admin is kept, equal to role = 'admin',
-- for the code and the mobile apps that read it.
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'

ALTER TABLE `users`
  ADD COLUMN `role` varchar(10) NOT NULL DEFAULT 'member' COMMENT 'admin, member or viewer (read-only); is_admin = 1 for admin' AFTER `is_admin`;

UPDATE `users` SET `role` = 'admin' WHERE `is_admin` = 1;
