-- Lock an account for 24 hours after 5 failed logins (unlocked by an administrator).
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'locked_until'

ALTER TABLE `users`
  ADD COLUMN `failed_logins` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 AFTER `last_login`,
  ADD COLUMN `locked_until` datetime DEFAULT NULL AFTER `failed_logins`;
