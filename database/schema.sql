-- =============================================
-- Computer-Based Assessment (CBA) System
-- MySQL / MariaDB schema
-- Deploy-safe: import ONCE. Redeploying PHP files
-- never touches data because DB lives separately.
-- =============================================
CREATE DATABASE IF NOT EXISTS cba_system
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cba_system;

-- Sections (e.g. BSIT-1A, Grade 10 - Diamond)
CREATE TABLE IF NOT EXISTS sections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_by INT NULL,
  assigned_teacher_id INT NULL COMMENT 'NULL = shared; set = visible only to that teacher (+creator)',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Users: admin / teacher / student in one table
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fullname VARCHAR(150) NOT NULL,
  lastname VARCHAR(100) NULL,
  firstname VARCHAR(100) NULL,
  mi VARCHAR(10) NULL,
  gender ENUM('Male','Female','Other') NOT NULL DEFAULT 'Other',
  section_id INT NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','teacher','student') NOT NULL DEFAULT 'student',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_section FOREIGN KEY (section_id)
    REFERENCES sections(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Exams created by teachers
CREATE TABLE IF NOT EXISTS exams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  teacher_id INT NOT NULL,
  section_id INT NULL COMMENT 'NULL = open to all sections',
  time_limit_minutes INT NOT NULL DEFAULT 60,
  passing_percent DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
  shuffle_questions TINYINT(1) NOT NULL DEFAULT 0,
  allow_retake TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_exams_teacher FOREIGN KEY (teacher_id)
    REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_exams_section FOREIGN KEY (section_id)
    REFERENCES sections(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Per-student exam assignment (for remedial, make-up, accommodations)
CREATE TABLE IF NOT EXISTS exam_students (
  exam_id INT NOT NULL,
  student_id INT NOT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (exam_id, student_id),
  CONSTRAINT fk_exam_students_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  CONSTRAINT fk_exam_students_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Questions belonging to an exam
CREATE TABLE IF NOT EXISTS questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_id INT NOT NULL,
  question_text TEXT NOT NULL,
  qtype ENUM('mcq','truefalse','identification','essay') NOT NULL DEFAULT 'mcq',
  option_a VARCHAR(500) NULL,
  option_b VARCHAR(500) NULL,
  option_c VARCHAR(500) NULL,
  option_d VARCHAR(500) NULL,
  correct_answer TEXT NOT NULL COMMENT 'mcq: A/B/C/D, truefalse: True/False, identification: exact text',
  points INT NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_questions_exam FOREIGN KEY (exam_id)
    REFERENCES exams(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- One row per student submission
CREATE TABLE IF NOT EXISTS attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_id INT NOT NULL,
  student_id INT NOT NULL,
  score DECIMAL(8,2) NOT NULL DEFAULT 0,
  total DECIMAL(8,2) NOT NULL DEFAULT 0,
  percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
  started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  submitted_at TIMESTAMP NULL,
  needs_grading SMALLINT NOT NULL DEFAULT 0 COMMENT '1 = has essay answers awaiting manual grading',
  shuffle_seed INT,
  CONSTRAINT fk_attempts_exam FOREIGN KEY (exam_id)
    REFERENCES exams(id) ON DELETE CASCADE,
  CONSTRAINT fk_attempts_student FOREIGN KEY (student_id)
    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Per-question answers inside an attempt
CREATE TABLE IF NOT EXISTS answers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attempt_id INT NOT NULL,
  question_id INT NOT NULL,
  student_answer TEXT NULL,
  is_correct TINYINT(1) NOT NULL DEFAULT 0,
  points_earned DECIMAL(8,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_answers_attempt FOREIGN KEY (attempt_id)
    REFERENCES attempts(id) ON DELETE CASCADE,
  CONSTRAINT fk_answers_question FOREIGN KEY (question_id)
    REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed: default section. The admin account is created by install.php
-- (it hashes the password correctly with password_hash()).
INSERT IGNORE INTO sections (id, name, description) VALUES
  (1, 'BSIT-1A', 'Default section');
