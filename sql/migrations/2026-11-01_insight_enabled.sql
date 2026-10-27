-- Insights enabled or not: the disabled ones are not shown nor run (the Insights tab
-- offers them again with its + button). The insights already installed stay enabled.
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budget_insight' AND COLUMN_NAME = 'enabled'

ALTER TABLE `budget_insight`
  ADD COLUMN `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Shown in the Insights tab (0: offered by its + button)' AFTER `sql`;
