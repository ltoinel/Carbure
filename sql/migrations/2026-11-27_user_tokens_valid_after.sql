-- Server-side revocation of the sessions (#533): the JWTs issued up to this time (Unix
-- seconds) are refused. Set by a password change and by "sign out everywhere"; NULL =
-- nothing revoked.
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tokens_valid_after'

ALTER TABLE `users`
  ADD COLUMN `tokens_valid_after` int(10) unsigned DEFAULT NULL COMMENT 'Sessions revoked up to this time (Unix seconds): the JWTs issued until then are refused';
