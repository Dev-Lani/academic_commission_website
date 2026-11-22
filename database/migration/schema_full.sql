-- =====================================================================
-- acw : FULL SCHEMA (merged schema.sql + schema_inc2 ... inc10)
-- Para sa FRESH install. Safe i-run ulit (IF NOT EXISTS / INSERT IGNORE).
-- Run:  mysql -u root < schema_full.sql
--
-- Para maging admin: mag-register muna, tapos:
--   UPDATE users SET role='admin' WHERE username='your_username';
-- =====================================================================

CREATE DATABASE IF NOT EXISTS acw CHARACTER SET utf8mb4;
USE acw;

-- ---------------------------------------------------------------------
-- USERS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(30) NOT NULL UNIQUE,
  email VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('client','commissioner','admin') NOT NULL,
  school VARCHAR(100) NULL,
  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  tier VARCHAR(20) NOT NULL DEFAULT 'New',   -- commissioners start at 'Bronze' (set by app on register)
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SUBJECTS / POSTS / VOTES
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  slug VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS posts (
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
  INDEX idx_subject_score (subject_id, score),
  INDEX idx_status_subject (status, subject_id, score),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS votes (
  user_id INT NOT NULL,
  post_id INT NOT NULL,
  value TINYINT NOT NULL,
  PRIMARY KEY (user_id, post_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- OFFERS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS offers (
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
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ORDERS (includes escrow / delivery columns from inc5)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  post_id INT NOT NULL,
  offer_id INT NOT NULL,
  client_id INT NOT NULL,
  commissioner_id INT NOT NULL,
  price INT NOT NULL,
  escrow_status ENUM('none','held','released','refunded') NOT NULL DEFAULT 'none',
  fee INT NOT NULL DEFAULT 0,
  deliver_by DATETIME NOT NULL,
  status ENUM('pending_agree','active','delivered','disputed','declined','expired','cancelled','completed')
         NOT NULL DEFAULT 'pending_agree',
  accepted_at DATETIME NOT NULL,
  agree_by DATETIME NOT NULL,
  started_at DATETIME NULL,
  delivered_at DATETIME NULL,
  review_by DATETIME NULL,
  preview_started_at DATETIME NULL,
  preview_expires_at DATETIME NULL,
  completed_at DATETIME NULL,
  UNIQUE KEY uniq_offer (offer_id),
  INDEX idx_status (status, agree_by),
  INDEX idx_comm_status (commissioner_id, status),
  INDEX idx_client_status (client_id, status),
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (commissioner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- NOTIFICATIONS (needs orders)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  post_id INT NOT NULL,
  order_id INT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'offer',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_note (user_id, post_id, type),
  INDEX idx_user_read (user_id, is_read),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- PER-ORDER CHAT (insert-only, evidence for disputes)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  sender_id INT NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_order_msg (order_id, id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Unread tracking per order chat
CREATE TABLE IF NOT EXISTS chat_reads (
  order_id INT NOT NULL,
  user_id INT NOT NULL,
  last_id INT NOT NULL DEFAULT 0,
  PRIMARY KEY (order_id, user_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- DELIVERY: output, history, view logs
-- ---------------------------------------------------------------------
-- Current delivery: final file + view-only preview (PDF/PNG/JPG)
CREATE TABLE IF NOT EXISTS order_outputs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  final_name VARCHAR(160) NOT NULL,
  final_stored CHAR(32) NOT NULL,
  preview_stored CHAR(32) NOT NULL,
  preview_mime VARCHAR(30) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uniq_output (order_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Earlier versions, kept when the commissioner uploads a revision
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
) ENGINE=InnoDB;

-- Every time the client opens the preview page
CREATE TABLE IF NOT EXISTS view_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  user_id INT NOT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_view_order (order_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Client asks for changes after delivery (open = fulfilled_at IS NULL)
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
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- DISPUTES / TRANSACTIONS / RATINGS
-- ---------------------------------------------------------------------
-- One dispute per order; admin resolution columns included (inc6)
CREATE TABLE IF NOT EXISTS disputes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  opened_by INT NOT NULL,
  reason TEXT NOT NULL,
  status ENUM('open','resolved') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL,
  resolution ENUM('release','refund') NULL,
  admin_id INT NULL,
  admin_note TEXT NULL,
  resolved_at DATETIME NULL,
  UNIQUE KEY uniq_dispute (order_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Simulated escrow ledger. user_id is NULL for the platform's own fee row.
CREATE TABLE IF NOT EXISTS transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  user_id INT NULL,
  type ENUM('hold','release','fee','refund') NOT NULL,
  amount INT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_tx_order (order_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Both sides rate each completed order, once
CREATE TABLE IF NOT EXISTS ratings (
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
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- BOOKMARKS / COMMISSIONER PROFILE
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bookmarks (
  user_id INT NOT NULL,
  post_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, post_id),
  INDEX idx_bm_user (user_id, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS commissioner_profiles (
  user_id INT NOT NULL PRIMARY KEY,
  bio VARCHAR(500) NOT NULL DEFAULT '',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS commissioner_skills (
  user_id INT NOT NULL,
  subject_id INT NOT NULL,
  PRIMARY KEY (user_id, subject_id),
  INDEX idx_cs_subject (subject_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SEED: SUBJECTS (INSERT IGNORE = walang duplicate kapag inulit)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO subjects (name, slug) VALUES
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
('Other','other'),
('Errands & Personal Tasks','errands-personal'),
('Tutoring & Coaching','tutoring-coaching'),
('Events & Organizing','events-organizing'),
('Translation & Languages','translation-languages');
