-- Upgrade a database created from the original FiscalPoint.sql dump to the current schema.
--
--   mysqldump -u root -p FiscalPoint > backup_before_upgrade.sql      <-- take a backup first
--   mysql -u root -p FiscalPoint < database/migrations/001_ml_overhaul.sql
--
-- Budget/Income months must already be in YYYY-MM format (see docs/DATABASE.md for the
-- check query). Rows that cannot be kept are deleted, so review the SELECTs at the top first.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------- inspect (read-only)
SELECT 'orphaned or invalid expenses to delete' AS check_name, COUNT(*) AS n_rows
  FROM Expense WHERE Uid IS NULL OR amount IS NULL OR amount <= 0 OR date IS NULL OR category IS NULL OR category = '';
SELECT 'duplicate budget rows to delete' AS check_name, COUNT(*) - COUNT(DISTINCT Uid, Month) AS n_rows FROM Budget;
SELECT 'duplicate income rows to delete' AS check_name, COUNT(*) - COUNT(DISTINCT Uid, Month) AS n_rows FROM Income;
SELECT 'months not in YYYY-MM format (fix manually before continuing)' AS check_name,
       (SELECT COUNT(*) FROM Budget WHERE Month NOT REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$')
     + (SELECT COUNT(*) FROM Income WHERE Month NOT REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$') AS n_rows;
SELECT 'duplicate emails (fix manually before continuing)' AS check_name, COUNT(*) - COUNT(DISTINCT email) AS n_rows FROM User;

-- ---------------------------------------------------------------- clean data
DELETE FROM Expense WHERE Uid IS NULL OR amount IS NULL OR amount <= 0 OR date IS NULL OR category IS NULL OR category = '';
UPDATE Expense SET description = '' WHERE description IS NULL;
UPDATE Expense SET Payment_Method = 'Other' WHERE Payment_Method IS NULL OR Payment_Method = '';

-- keep the most recent row per (user, month)
DELETE b1 FROM Budget b1 JOIN Budget b2 ON b1.Uid = b2.Uid AND b1.Month = b2.Month AND b1.Bid < b2.Bid;
DELETE i1 FROM Income i1 JOIN Income i2 ON i1.Uid = i2.Uid AND i1.Month = i2.Month AND i1.Iid < i2.Iid;
DELETE FROM Budget WHERE Uid IS NULL OR Amount <= 0;
DELETE FROM Income WHERE Uid IS NULL OR Income <= 0;

-- reset tokens used to be stored in plain text; all outstanding links are invalidated
UPDATE User SET reset_token = NULL, token_expires = NULL;
UPDATE User SET Uname = email WHERE Uname IS NULL OR Uname = '';

-- ---------------------------------------------------------------- constraints and indexes
ALTER TABLE User
  MODIFY Uname VARCHAR(255) NOT NULL,
  MODIFY email VARCHAR(255) NOT NULL,
  MODIFY Password VARCHAR(255) NOT NULL,
  MODIFY reset_token CHAR(64) DEFAULT NULL,
  ADD UNIQUE KEY uq_user_email (email),
  ADD KEY idx_user_reset_token (reset_token);

ALTER TABLE Expense
  MODIFY Uid INT NOT NULL,
  MODIFY category VARCHAR(60) NOT NULL,
  MODIFY amount DECIMAL(10,2) NOT NULL,
  MODIFY date DATE NOT NULL,
  MODIFY description VARCHAR(255) NOT NULL DEFAULT '',
  MODIFY Payment_Method VARCHAR(60) NOT NULL,
  ADD KEY idx_expense_user_date (Uid, date),
  ADD KEY idx_expense_category (category);

ALTER TABLE Budget
  MODIFY Uid INT NOT NULL,
  MODIFY Month CHAR(7) NOT NULL,
  MODIFY Amount DECIMAL(12,2) NOT NULL,
  ADD UNIQUE KEY uq_budget_user_month (Uid, Month);

ALTER TABLE Income
  MODIFY Uid INT NOT NULL,
  MODIFY Month CHAR(7) NOT NULL,
  MODIFY Income DECIMAL(12,2) NOT NULL,
  ADD UNIQUE KEY uq_income_user_month (Uid, Month);
