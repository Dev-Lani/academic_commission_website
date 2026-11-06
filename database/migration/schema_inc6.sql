USE acw;

ALTER TABLE transactions MODIFY type ENUM('hold','release','fee','refund') NOT NULL;

-- Admin resolution of disputes
ALTER TABLE disputes
  ADD COLUMN resolution ENUM('release','refund') NULL,
  ADD COLUMN admin_id INT NULL,
  ADD COLUMN admin_note TEXT NULL,
  ADD COLUMN resolved_at DATETIME NULL,
  ADD FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL;

-- Required ratings: both sides rate each completed order, once.
CREATE TABLE ratings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  rater_id INT NOT NULL,
  ratee_id INT NOT NULL,
  stars TINYINT NOT NULL,
  comment TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY one_rating (order_id, rater_id),
  INDEX idx_ratee (ratee_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (rater_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (ratee_id) REFERENCES users(id) ON DELETE CASCADE
);

-- To make an admin: register normally, then run:
--   UPDATE users SET role='admin' WHERE username='your_username';