USE mini_system_db;

CREATE TABLE IF NOT EXISTS messages (
  id          INT           AUTO_INCREMENT PRIMARY KEY,
  sender_id   INT           NOT NULL,
  receiver_id INT           NULL,
  entity_id   INT           NULL,
  message     TEXT          NOT NULL,
  is_read     TINYINT(1)    NOT NULL DEFAULT 0,
  created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_msg_sender   FOREIGN KEY (sender_id)   REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_receiver FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_msg_entity   FOREIGN KEY (entity_id)   REFERENCES entities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
