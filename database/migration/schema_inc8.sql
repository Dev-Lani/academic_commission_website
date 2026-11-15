USE acw;

-- Everyday (non-school) subjects, so people can post other kinds of tasks too.
-- INSERT IGNORE means running this file twice will not create duplicates.
INSERT IGNORE INTO subjects (name, slug) VALUES
('Errands & Personal Tasks','errands-personal'),
('Tutoring & Coaching','tutoring-coaching'),
('Events & Organizing','events-organizing'),
('Translation & Languages','translation-languages');

-- Saved requests: a commissioner stars a request to find it again later.
CREATE TABLE IF NOT EXISTS bookmarks (
  user_id INT NOT NULL,
  post_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, post_id),
  INDEX idx_bm_user (user_id, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
);
