USE acw;

-- Revision requests: after a delivery the client can ask for changes instead of only approving or disputing.
-- No change to the orders table. An order with an open revision (fulfilled_at IS NULL) is "active" again.
-- Safe to run twice.
CREATE TABLE IF NOT EXISTS revision_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  round_no TINYINT NOT NULL,
  notes TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  due_by DATETIME NOT NULL,
  fulfilled_at DATETIME NULL,
  UNIQUE KEY uniq_round (order_id, round_no),
  INDEX idx_rr_open (fulfilled_at, due_by),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- Earlier versions of a delivery are kept here when the commissioner uploads a revision (evidence for disputes).
CREATE TABLE IF NOT EXISTS order_output_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  version TINYINT NOT NULL,
  final_name VARCHAR(160) NOT NULL,
  final_stored CHAR(32) NOT NULL,
  preview_stored CHAR(32) NOT NULL,
  preview_mime VARCHAR(30) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_hist_order (order_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);
