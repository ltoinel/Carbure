-- Lifetime of the access tokens of the AI agents, chosen at their creation.
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_tokens' AND COLUMN_NAME = 'expires_at'

ALTER TABLE `api_tokens` ADD COLUMN `expires_at` datetime DEFAULT NULL COMMENT 'NULL: never expires' AFTER `last_used_at`;
