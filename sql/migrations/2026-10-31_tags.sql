-- Tags of a project (a trip, works...) limited in time: a second axis, apart from the
-- categories, to follow the whole cost of a project. A transaction may have several
-- tags; deleting a tag or a transaction deletes their links.
-- applied-if: SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tag'

CREATE TABLE `tag` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(40) NOT NULL COMMENT 'Name of the tag, without its #',
  `color` varchar(30) NOT NULL DEFAULT '' COMMENT 'Color (SwiftUI name or hex, may be empty)',
  `start_date` date NOT NULL COMMENT 'First day of the project',
  `end_date` date DEFAULT NULL COMMENT 'Last day of the project (NULL: still running); archived after it',
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_tag_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transaction_tag` (
  `transaction_id` bigint(20) unsigned NOT NULL,
  `tag_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`transaction_id`, `tag_id`),
  KEY `transaction_tag_tag` (`tag_id`),
  CONSTRAINT `fk_transaction_tag_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `bank_transaction` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_transaction_tag_tag` FOREIGN KEY (`tag_id`) REFERENCES `tag` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
