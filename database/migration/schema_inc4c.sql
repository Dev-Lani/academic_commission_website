USE acw;

-- Tracks how far each person has read in each order's chat (drives the Messages unread badge).
CREATE TABLE chat_reads (
  order_id INT NOT NULL,
  user_id INT NOT NULL,
  last_id INT NOT NULL DEFAULT 0,
  PRIMARY KEY (order_id, user_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Chat messages are no longer notifications; they have their own Messages page.
DELETE FROM notifications WHERE type = 'message';