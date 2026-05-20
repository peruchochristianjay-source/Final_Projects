USE mini_system_db;

-- ============================================================
-- REMIT REQUESTS
-- Treasurer submits collection remittance to SAS Admin
-- Flow: Treasurer collects membership fees → submits remit request → SAS approves
-- ============================================================
CREATE TABLE IF NOT EXISTS remit_requests (
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
-- SAS Admin releases funds to org/club for activities/events
-- Flow: SAS Admin creates release → treasurer receives funds for event use
-- ============================================================
CREATE TABLE IF NOT EXISTS fund_releases (
  id           INT           AUTO_INCREMENT PRIMARY KEY,
  entity_id    INT           NOT NULL,
  released_by  INT           NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  purpose      VARCHAR(255)  NOT NULL,
  release_date DATE          NOT NULL,
  status       ENUM('Released','Cancelled') NOT NULL DEFAULT 'Released',
  remarks      VARCHAR(255)  NULL,
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fr2_entity  FOREIGN KEY (entity_id)   REFERENCES entities(id) ON DELETE CASCADE,
  CONSTRAINT fk_fr2_user    FOREIGN KEY (released_by) REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
