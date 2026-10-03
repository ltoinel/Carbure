-- Icon of each insight, chosen by the administrator (Insights tab).
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budget_insight' AND COLUMN_NAME = 'icon'

ALTER TABLE `budget_insight`
  ADD COLUMN `icon` varchar(50) DEFAULT NULL COMMENT 'Material icon (default: chosen from the name)' AFTER `color`;
