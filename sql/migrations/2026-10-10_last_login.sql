-- Date of the last successful login of each user (Users tab).
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login'

ALTER TABLE `users` ADD COLUMN `last_login` datetime DEFAULT NULL AFTER `alert_threshold`;
