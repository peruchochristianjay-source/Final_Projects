CREATE DATABASE IF NOT EXISTS mini_system_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE mini_system_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS accredited_history;
DROP TABLE IF EXISTS accredited_entries;
DROP TABLE IF EXISTS fund_releases;
DROP TABLE IF EXISTS remit_requests;
DROP TABLE IF EXISTS financial_records;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS entity_funds;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS entities;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- ENTITIES
-- Parent table for both organizations and clubs
-- ============================================================
CREATE TABLE entities (
  id         INT          AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(180) NOT NULL UNIQUE,
  short_name VARCHAR(100) NULL,
  category   ENUM('organization','club') NOT NULL,
  photo_path VARCHAR(255) NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- USERS
-- System users: Admin (SAS) and Officers (Treasurers)
-- ============================================================
CREATE TABLE users (
  id                INT          AUTO_INCREMENT PRIMARY KEY,
  full_name         VARCHAR(150) NOT NULL,
  email             VARCHAR(150) NOT NULL UNIQUE,
  password_hash     VARCHAR(255) NULL,
  role              ENUM('admin','officer') NOT NULL,
  entity_id         INT          NULL,
  auth_provider     ENUM('local','google') NOT NULL DEFAULT 'local',
  google_id         VARCHAR(191) NULL UNIQUE,
  avatar_url        VARCHAR(255) NULL,
  is_google_account TINYINT(1)   NOT NULL DEFAULT 0,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_entity FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- STUDENTS
-- Students registered under an org or club
-- ============================================================
CREATE TABLE students (
  id             INT           AUTO_INCREMENT PRIMARY KEY,
  student_id     VARCHAR(40)   NOT NULL UNIQUE,
  full_name      VARCHAR(180)  NOT NULL,
  course         VARCHAR(80)   NOT NULL,
  year_level     VARCHAR(30)   NOT NULL DEFAULT '',
  entity_id      INT           NULL,
  amount_due     DECIMAL(10,2) NOT NULL DEFAULT 20.00,
  payment_status ENUM('Paid','Unpaid') NOT NULL DEFAULT 'Unpaid',
  created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_student_entity FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ENTITY FUNDS
-- Tracks the fund balance per org/club
-- ============================================================
CREATE TABLE entity_funds (
  entity_id      INT           PRIMARY KEY,
  collections    DECIMAL(12,2) NOT NULL DEFAULT 0,
  expenses       DECIMAL(12,2) NOT NULL DEFAULT 0,
  previous_funds DECIMAL(12,2) NOT NULL DEFAULT 0,
  updated_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ef_entity FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TRANSACTIONS
-- Every financial movement: Income or Expense
-- ============================================================
CREATE TABLE transactions (
  id          INT           AUTO_INCREMENT PRIMARY KEY,
  entity_id   INT           NULL,
  tx_date     DATE          NOT NULL,
  type        ENUM('Income','Expense') NOT NULL,
  description VARCHAR(255)  NULL,
  amount      DECIMAL(12,2) NOT NULL,
  source      ENUM('manual','financial','student','system','admin_release') NOT NULL DEFAULT 'manual',
  created_by  INT           NULL,
  created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_txn_entity FOREIGN KEY (entity_id)  REFERENCES entities(id) ON DELETE SET NULL,
  CONSTRAINT fk_txn_user   FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- FINANCIAL RECORDS
-- Records of Remit (treasurer to SAS) and Expense entries
-- ============================================================
CREATE TABLE financial_records (
  id          INT           AUTO_INCREMENT PRIMARY KEY,
  entity_id   INT           NULL,
  record_date DATE          NOT NULL,
  type        ENUM('Remit','Expense','Income') NOT NULL,
  description VARCHAR(255)  NULL,
  amount      DECIMAL(12,2) NOT NULL,
  created_by  INT           NULL,
  created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fr_entity FOREIGN KEY (entity_id)  REFERENCES entities(id) ON DELETE SET NULL,
  CONSTRAINT fk_fr_user   FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- REMIT REQUESTS
-- Treasurer submits collected membership fees to SAS Admin
-- ============================================================
CREATE TABLE remit_requests (
  id           INT           AUTO_INCREMENT PRIMARY KEY,
  entity_id    INT           NOT NULL,
  treasurer_id INT           NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  description  VARCHAR(255)  NULL,
  status       ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  request_date DATE          NOT NULL,
  reviewed_at  TIMESTAMP     NULL,
  reviewed_by  INT           NULL,
  remarks      VARCHAR(255)  NULL,
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rr_entity   FOREIGN KEY (entity_id)    REFERENCES entities(id) ON DELETE CASCADE,
  CONSTRAINT fk_rr_user     FOREIGN KEY (treasurer_id) REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_rr_reviewer FOREIGN KEY (reviewed_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- FUND RELEASES
-- SAS Admin releases funds to org/club for activities and events
-- ============================================================
CREATE TABLE fund_releases (
  id           INT           AUTO_INCREMENT PRIMARY KEY,
  entity_id    INT           NOT NULL,
  released_by  INT           NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  purpose      VARCHAR(255)  NOT NULL,
  release_date DATE          NOT NULL,
  status       ENUM('Released','Cancelled') NOT NULL DEFAULT 'Released',
  remarks      VARCHAR(255)  NULL,
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fr2_entity FOREIGN KEY (entity_id)   REFERENCES entities(id) ON DELETE CASCADE,
  CONSTRAINT fk_fr2_user   FOREIGN KEY (released_by) REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ACCREDITED ENTRIES
-- List of accredited organizations and clubs
-- ============================================================
CREATE TABLE accredited_entries (
  id              INT          AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(180) NOT NULL,
  entry_type      ENUM('Organization','Club') NOT NULL,
  adviser         VARCHAR(180) NULL,
  academic_year   VARCHAR(30)  NULL,
  accredited_date DATE         NULL,
  expiry_date     DATE         NULL,
  status          ENUM('Accredited','Pending','Expired') NOT NULL DEFAULT 'Pending',
  remarks         VARCHAR(255) NULL,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ACCREDITED HISTORY
-- Audit trail for accredited entries changes
-- ============================================================
CREATE TABLE accredited_history (
  id                  INT  AUTO_INCREMENT PRIMARY KEY,
  accredited_entry_id INT  NULL,
  action              ENUM('UPDATED','DELETED') NOT NULL,
  payload             JSON NOT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ah_entry FOREIGN KEY (accredited_entry_id)
    REFERENCES accredited_entries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
