-- API tokens: long-lived read-only access for the MCP server (/api/mcp), used by Claude.
-- Apply BEFORE deploying the code that uses this table.

CREATE TABLE `api_tokens` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(50) NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 of the token, the token itself is never stored',
  `token_hint` varchar(16) NOT NULL COMMENT 'Start of the token, to recognize it',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `fk_api_token_user` (`user_id`),
  CONSTRAINT `fk_api_token_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
