USE acw;

CREATE TABLE offers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  post_id INT NOT NULL,
  user_id INT NOT NULL,
  price INT NOT NULL,
  deliver_by DATETIME NOT NULL,
  message TEXT NOT NULL,
  sample TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY one_offer (post_id, user_id),
  INDEX idx_post_price (post_id, price),
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  post_id INT NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'offer',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_note (user_id, post_id, type),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
);

-- commissioners dapat Bronze ang start (hindi "New")
UPDATE users SET tier='Bronze' WHERE role='commissioner' AND tier='New';