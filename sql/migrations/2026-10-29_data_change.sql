-- Change markers of the household data: one version per kind of data (transactions,
-- accounts, categories, budgets, insights, settings), bumped by every write, so that the
-- apps and the portal reload only what changed (GET /api/changes).
-- applied-if: SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'data_change'

CREATE TABLE `data_change` (
  `kind` varchar(20) NOT NULL COMMENT 'Kind of data: transactions, accounts, categories, budgets, insights, settings',
  `version` bigint(20) unsigned NOT NULL COMMENT 'Bumped by every write of the kind',
  `updated_at` datetime(3) NOT NULL COMMENT 'Date of the last write, for information',
  PRIMARY KEY (`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
