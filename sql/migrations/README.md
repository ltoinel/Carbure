# Migrations

`sql/carbure.sql` is the complete schema: a new database is created from it alone.
A change of schema goes into **both**:

1. `sql/carbure.sql` (the table definitions, and its version in `schema_migrations`);
2. a migration here, `YYYY-MM-DD_name.sql`, which brings the existing databases up to
   date. The migrations are applied in the order of their names when the container
   starts (`tools/migrate.php`), or from the banner shown to the administrators.

Each migration starts with a query that tells whether it is already applied (a
database installed from a `carbure.sql` that already contains it): the migration is
then only recorded.

```sql
-- Notify the household when a new transaction matches a categorization rule.
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_transaction_category_keyword' AND COLUMN_NAME = 'notify'

ALTER TABLE `bank_transaction_category_keyword`
  ADD COLUMN `notify` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Push to the household when a new transaction matches';
```

The migrations of version 1.0 (up to `2026-10-13_rule_notify`) are included in
`carbure.sql` and were removed: every database is at this level (schema version
`2026-10-13_base` for a database created from `carbure.sql`).
