-- OAuth 2.1 for the MCP server: the AI agents that only take an URL (Claude web, Desktop
-- and mobile connectors) register themselves and get an access token after the consent
-- of the user. The access tokens are API tokens (api_tokens), with a refresh token.
-- applied-if: SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oauth_clients'

CREATE TABLE `oauth_clients` (
  `client_id` varchar(64) NOT NULL,
  `client_secret_hash` char(64) DEFAULT NULL COMMENT 'SHA-256 of the secret, NULL for a public client (PKCE only)',
  `name` varchar(100) NOT NULL,
  `redirect_uris` varchar(2000) NOT NULL COMMENT 'JSON array',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `oauth_codes` (
  `code_hash` char(64) NOT NULL COMMENT 'SHA-256 of the authorization code',
  `client_id` varchar(64) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `redirect_uri` varchar(500) NOT NULL,
  `code_challenge` varchar(128) NOT NULL COMMENT 'PKCE, S256',
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`code_hash`),
  KEY `fk_oauth_code_client` (`client_id`),
  KEY `fk_oauth_code_user` (`user_id`),
  CONSTRAINT `fk_oauth_code_client` FOREIGN KEY (`client_id`) REFERENCES `oauth_clients` (`client_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oauth_code_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `api_tokens`
  ADD COLUMN `client_id` varchar(64) DEFAULT NULL COMMENT 'OAuth client that got the token (NULL: created in the portal)',
  ADD COLUMN `refresh_hash` char(64) DEFAULT NULL COMMENT 'SHA-256 of the OAuth refresh token',
  ADD UNIQUE KEY `refresh_hash` (`refresh_hash`);
