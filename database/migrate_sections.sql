-- Manual upgrade (v2): section -> teacher assignment.
-- Normally NOT needed: install.php applies this automatically.
-- Run only if you manage the database by hand.
-- Works on MySQL and PostgreSQL.

ALTER TABLE sections ADD COLUMN assigned_teacher_id INT;

-- Teacher-created sections become visible only to their creator.
UPDATE sections SET assigned_teacher_id = created_by
WHERE assigned_teacher_id IS NULL
  AND created_by IN (SELECT id FROM users WHERE role = 'teacher');
