-- Insight alerts: a user is alerted when the value of the month of an enabled insight
-- (absolute value, as on its card) goes above or below his threshold, once per month
-- (again when the threshold is changed). Deleting an insight or its user drops its alerts.
-- applied-if: SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insight_alert'

CREATE TABLE `insight_alert` (
  `user_id` int(10) unsigned NOT NULL,
  `insight_id` int(10) unsigned NOT NULL,
  `direction` enum('above','below') NOT NULL COMMENT 'Alert when the value goes above or below the threshold',
  `threshold` decimal(12,2) NOT NULL COMMENT 'Amount compared with the absolute value of the month',
  `notified_month` date DEFAULT NULL COMMENT 'First day of the month last alerted (NULL: not yet, or threshold changed)',
  PRIMARY KEY (`user_id`, `insight_id`),
  KEY `insight_alert_insight` (`insight_id`),
  CONSTRAINT `fk_insight_alert_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_insight_alert_insight` FOREIGN KEY (`insight_id`) REFERENCES `budget_insight` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
