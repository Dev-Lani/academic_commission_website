USE acw;

CREATE TABLE subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  slug VARCHAR(60) NOT NULL UNIQUE
);

CREATE TABLE posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  subject_id INT NOT NULL,
  title VARCHAR(120) NOT NULL,
  type VARCHAR(30) NOT NULL,
  pages INT NOT NULL,
  budget_min INT NOT NULL,
  budget_max INT NOT NULL,
  deadline DATETIME NOT NULL,
  description TEXT NOT NULL,
  status ENUM('open','claimed','in_progress','completed') NOT NULL DEFAULT 'open',
  score INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id),
  INDEX idx_subject_score (subject_id, score)
);

CREATE TABLE votes (
  user_id INT NOT NULL,
  post_id INT NOT NULL,
  value TINYINT NOT NULL,
  PRIMARY KEY (user_id, post_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
);

INSERT INTO subjects (name, slug) VALUES
('Computer Science','computer-science'),
('Information Technology','information-technology'),
('Programming & Software','programming-software'),
('Engineering','engineering'),
('Architecture','architecture'),
('Mathematics','mathematics'),
('Statistics & Data','statistics-data'),
('Physics','physics'),
('Chemistry','chemistry'),
('Biology','biology'),
('Nursing','nursing'),
('Medical Technology','medical-technology'),
('Pharmacy','pharmacy'),
('Psychology','psychology'),
('Business Administration','business-administration'),
('Accountancy','accountancy'),
('Marketing','marketing'),
('Economics','economics'),
('Education','education'),
('English & Literature','english-literature'),
('Filipino','filipino'),
('History','history'),
('Political Science','political-science'),
('Sociology','sociology'),
('Communication & Media','communication-media'),
('Tourism & Hospitality','tourism-hospitality'),
('Criminology','criminology'),
('Agriculture','agriculture'),
('Fine Arts & Design','fine-arts-design'),
('Research & Thesis','research-thesis'),
('Other','other');