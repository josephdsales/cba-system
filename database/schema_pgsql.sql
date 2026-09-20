-- =============================================
-- CBA System - PostgreSQL schema (for Render)
-- Import ONCE (install.php does this automatically).
-- Redeploying files never touches data.
-- =============================================

CREATE TABLE IF NOT EXISTS sections (
  id SERIAL PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_by INT NULL,
  assigned_teacher_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
  id SERIAL PRIMARY KEY,
  fullname VARCHAR(150) NOT NULL,
  lastname VARCHAR(100) NULL,
  firstname VARCHAR(100) NULL,
  mi VARCHAR(10) NULL,
  gender VARCHAR(10) NOT NULL DEFAULT 'Other'
    CHECK (gender IN ('Male','Female','Other')),
  section_id INT NULL REFERENCES sections(id) ON DELETE SET NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(10) NOT NULL DEFAULT 'student'
    CHECK (role IN ('admin','teacher','student')),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS exams (
  id SERIAL PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  teacher_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  section_id INT NULL REFERENCES sections(id) ON DELETE SET NULL,
  time_limit_minutes INT NOT NULL DEFAULT 60,
  passing_percent NUMERIC(5,2) NOT NULL DEFAULT 50.00,
  status VARCHAR(10) NOT NULL DEFAULT 'draft'
    CHECK (status IN ('draft','published','closed')),
  shuffle_questions SMALLINT NOT NULL DEFAULT 0,
  allow_retake SMALLINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS exam_students (
  exam_id INT NOT NULL REFERENCES exams(id) ON DELETE CASCADE,
  student_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (exam_id, student_id)
);

CREATE TABLE IF NOT EXISTS section_teachers (
  section_id INT NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
  teacher_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (section_id, teacher_id)
);

CREATE TABLE IF NOT EXISTS questions (
  id SERIAL PRIMARY KEY,
  exam_id INT NOT NULL REFERENCES exams(id) ON DELETE CASCADE,
  question_text TEXT NOT NULL,
  qtype VARCHAR(15) NOT NULL DEFAULT 'mcq'
    CHECK (qtype IN ('mcq','truefalse','identification','essay')),
  option_a VARCHAR(500) NULL,
  option_b VARCHAR(500) NULL,
  option_c VARCHAR(500) NULL,
  option_d VARCHAR(500) NULL,
  correct_answer TEXT NOT NULL,
  points INT NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS attempts (
  id SERIAL PRIMARY KEY,
  exam_id INT NOT NULL REFERENCES exams(id) ON DELETE CASCADE,
  student_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  score NUMERIC(8,2) NOT NULL DEFAULT 0,
  total NUMERIC(8,2) NOT NULL DEFAULT 0,
  percentage NUMERIC(5,2) NOT NULL DEFAULT 0,
  started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  submitted_at TIMESTAMP NULL,
  needs_grading SMALLINT NOT NULL DEFAULT 0,
  shuffle_seed INT,
  CONSTRAINT idx_attempts_exam_student UNIQUE (exam_id, student_id)
);

CREATE TABLE IF NOT EXISTS answers (
  id SERIAL PRIMARY KEY,
  attempt_id INT NOT NULL REFERENCES attempts(id) ON DELETE CASCADE,
  question_id INT NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
  student_answer TEXT NULL,
  is_correct SMALLINT NOT NULL DEFAULT 0,
  points_earned NUMERIC(8,2) NOT NULL DEFAULT 0
);

INSERT INTO sections (id, name, description) VALUES (1, 'BSIT-1A', 'Default section')
ON CONFLICT (id) DO NOTHING;
SELECT setval(pg_get_serial_sequence('sections','id'), GREATEST((SELECT MAX(id) FROM sections), 1));
