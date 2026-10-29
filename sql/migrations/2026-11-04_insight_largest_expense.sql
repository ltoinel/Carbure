-- The starter insight "Largest expense" counts a split transaction once, with the amount
-- of the bank (the rest and its parts), instead of its biggest part. Only the insights whose
-- query is still the one of the installation are changed.
-- applied-if: SELECT COUNT(*) = 0 FROM budget_insight WHERE `sql` = 'SELECT MIN(amount) AS amount FROM bank_transaction WHERE amount < 0 AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = ''HORS-BUDGET'' AND id <> 0) AND MONTH(date) = {month} AND YEAR(date) = {year}'

UPDATE `budget_insight` SET `sql` = 'SELECT MIN(t.amount + IFNULL((SELECT SUM(p.amount) FROM bank_transaction p WHERE p.split_of = t.id), 0)) AS amount FROM bank_transaction t WHERE t.split_of IS NULL AND t.amount < 0 AND t.category NOT IN (SELECT id FROM bank_transaction_category WHERE type = ''HORS-BUDGET'' AND id <> 0) AND MONTH(t.date) = {month} AND YEAR(t.date) = {year}'
  WHERE `sql` = 'SELECT MIN(amount) AS amount FROM bank_transaction WHERE amount < 0 AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = ''HORS-BUDGET'' AND id <> 0) AND MONTH(date) = {month} AND YEAR(date) = {year}';
