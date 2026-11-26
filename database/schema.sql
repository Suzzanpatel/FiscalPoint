-- FiscalPoint database schema (MySQL 8.0.16+ / MariaDB 10.4+)
--
-- Fresh install:
--   mysql -u root -p -e "CREATE DATABASE FiscalPoint CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--   mysql -u root -p FiscalPoint < database/schema.sql
--   mysql -u root -p FiscalPoint < database/seed_demo.sql      (optional demo data)
--
-- Upgrading a database created from the old FiscalPoint.sql dump: use
-- database/migrations/001_ml_overhaul.sql instead of this file.
--
-- Fixes compared with the original dump:
--   * tables are created in dependency order (Income/Query referenced User before it existed,
--     so importing the old dump failed with a foreign-key error)
--   * Expense.Uid is NOT NULL (the dump contained orphaned expenses) and amounts must be > 0
--   * one budget / one income row per user and month is enforced with UNIQUE keys
--   * User.email is UNIQUE
--   * indexes for the queries the app and the ML service actually run
--   * reset_token stores a SHA-256 hash (64 hex chars), never the raw token
--   * no personal data is shipped with the schema

SET NAMES utf8mb4;
SET time_zone = '+00:00';

DROP TABLE IF EXISTS `Query`;
DROP TABLE IF EXISTS `Income`;
DROP TABLE IF EXISTS `Budget`;
DROP TABLE IF EXISTS `Expense`;
DROP TABLE IF EXISTS `User`;

CREATE TABLE `User` (
  `Uid`           INT          NOT NULL AUTO_INCREMENT,
  `Uname`         VARCHAR(255) NOT NULL,
  `email`         VARCHAR(255) NOT NULL,
  `Password`      VARCHAR(255) NOT NULL,              -- password_hash() output
  `Phone_no`      VARCHAR(10)  DEFAULT NULL,
  `Created_At`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `Role`          VARCHAR(20)  NOT NULL DEFAULT 'user',
  `reset_token`   CHAR(64)     DEFAULT NULL,          -- SHA-256 of the emailed token
  `token_expires` DATETIME     DEFAULT NULL,
  PRIMARY KEY (`Uid`),
  UNIQUE KEY `uq_user_email` (`email`),
  KEY `idx_user_reset_token` (`reset_token`),
  CONSTRAINT `chk_user_role` CHECK (`Role` IN ('user', 'admin'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `Expense` (
  `Eid`            INT           NOT NULL AUTO_INCREMENT,
  `Uid`            INT           NOT NULL,
  `category`       VARCHAR(60)   NOT NULL,
  `amount`         DECIMAL(10,2) NOT NULL,
  `date`           DATE          NOT NULL,
  `description`    VARCHAR(255)  NOT NULL DEFAULT '',
  `Payment_Method` VARCHAR(60)   NOT NULL,
  PRIMARY KEY (`Eid`),
  KEY `idx_expense_user_date` (`Uid`, `date`),
  KEY `idx_expense_category` (`category`),
  CONSTRAINT `fk_expense_user` FOREIGN KEY (`Uid`) REFERENCES `User` (`Uid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_expense_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `Budget` (
  `Bid`    INT           NOT NULL AUTO_INCREMENT,
  `Uid`    INT           NOT NULL,
  `Month`  CHAR(7)       NOT NULL,                    -- YYYY-MM
  `Amount` DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (`Bid`),
  UNIQUE KEY `uq_budget_user_month` (`Uid`, `Month`),
  CONSTRAINT `fk_budget_user` FOREIGN KEY (`Uid`) REFERENCES `User` (`Uid`) ON DELETE CASCADE,
  CONSTRAINT `chk_budget_amount` CHECK (`Amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `Income` (
  `Iid`    INT           NOT NULL AUTO_INCREMENT,
  `Uid`    INT           NOT NULL,
  `Month`  CHAR(7)       NOT NULL,                    -- YYYY-MM
  `Income` DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (`Iid`),
  UNIQUE KEY `uq_income_user_month` (`Uid`, `Month`),
  CONSTRAINT `fk_income_user` FOREIGN KEY (`Uid`) REFERENCES `User` (`Uid`) ON DELETE CASCADE,
  CONSTRAINT `chk_income_amount` CHECK (`Income` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `Query` (
  `Qid`            INT          NOT NULL AUTO_INCREMENT,
  `Uid`            INT          DEFAULT NULL,
  `Email`          VARCHAR(255) DEFAULT NULL,
  `Query_type`     VARCHAR(255) DEFAULT NULL,
  `Description`    TEXT         DEFAULT NULL,
  `Created_At`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `Status`         VARCHAR(20)  NOT NULL DEFAULT 'Pending',
  `Admin_Response` TEXT         DEFAULT NULL,
  `Response_Date`  DATETIME     DEFAULT NULL,
  PRIMARY KEY (`Qid`),
  KEY `idx_query_user` (`Uid`),
  CONSTRAINT `fk_query_user` FOREIGN KEY (`Uid`) REFERENCES `User` (`Uid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
