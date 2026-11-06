USE acw;

ALTER TABLE orders
  MODIFY status ENUM('pending_agree','active','delivered','disputed','declined','expired','cancelled','completed') NOT NULL DEFAULT 'pending_agree',
  ADD COLUMN escrow_status ENUM('none','held','released','refunded') NOT NULL DEFAULT 'none' AFTER price,
  ADD COLUMN fee INT NOT NULL DEFAULT 0 AFTER escrow_status,
  ADD COLUMN delivered_at DATETIME NULL,
  ADD COLUMN review_by DATETIME NULL,
  ADD COLUMN preview_started_at DATETIME NULL,
  ADD COLUMN preview_expires_at DATETIME NULL,
  ADD COLUMN completed_at DATETIME NULL;

-- One delivery per order: the final file plus a view-only preview (PDF/PNG/JPG).
CREATE TABLE order_outputs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  final_name VARCHAR(160) NOT NULL,
  final_stored CHAR(32) NOT NULL,
  preview_stored CHAR(32) NOT NULL,
  preview_mime VARCHAR(30) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uniq_output (order_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- Every time the client opens the preview page. Evidence for disputes.
CREATE TABLE view_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  user_id INT NOT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_view_order (order_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- One dispute per order. Admin resolution comes in Increment 6.
CREATE TABLE disputes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  opened_by INT NOT NULL,
  reason TEXT NOT NULL,
  status ENUM('open','resolved') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL,
  UNIQUE KEY uniq_dispute (order_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Simulated escrow ledger. user_id is NULL for the platform's own fee row.
CREATE TABLE transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  user_id INT NULL,
  type ENUM('hold','release','fee') NOT NULL,
  amount INT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_tx_order (order_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Orders already in progress get their escrow hold backfilled.
UPDATE orders SET escrow_status='held' WHERE status='active';
INSERT INTO transactions (order_id, user_id, type, amount, created_at)
  SELECT id, client_id, 'hold', price, COALESCE(started_at, accepted_at) FROM orders WHERE status='active';