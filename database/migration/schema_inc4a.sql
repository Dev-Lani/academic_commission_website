USE acw;

CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  post_id INT NOT NULL,
  offer_id INT NOT NULL,
  client_id INT NOT NULL,
  commissioner_id INT NOT NULL,
  price INT NOT NULL,
  deliver_by DATETIME NOT NULL,
  status ENUM('pending_agree','active','declined','expired','cancelled','completed') NOT NULL DEFAULT 'pending_agree',
  accepted_at DATETIME NOT NULL,
  agree_by DATETIME NOT NULL,
  started_at DATETIME NULL,
  UNIQUE KEY uniq_offer (offer_id),
  INDEX idx_status (status, agree_by),
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (commissioner_id) REFERENCES users(id) ON DELETE CASCADE
);

ALTER TABLE notifications
  ADD COLUMN order_id INT NULL AFTER post_id,
  ADD FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE;