USE acw;

-- Commissioner profile: a short bio, plus the subjects they are good at.
-- Separate tables so nothing in `users` changes. Safe to run twice.
CREATE TABLE IF NOT EXISTS commissioner_profiles (
  user_id INT NOT NULL PRIMARY KEY,
  bio VARCHAR(500) NOT NULL DEFAULT '',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS commissioner_skills (
  user_id INT NOT NULL,
  subject_id INT NOT NULL,
  PRIMARY KEY (user_id, subject_id),
  INDEX idx_cs_subject (subject_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);
