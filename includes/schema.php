<?php
// Shared schema creation + idempotent upgrades.
// Used by install.php (fresh setup) and db_migrate.php (building the new Neon DB).
// Safe to run repeatedly: every statement is IF NOT EXISTS or exception-tolerant.

function ensure_schema(PDO $db): void {
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $schema = $driver === 'pgsql' ? 'schema_pgsql.sql' : 'schema.sql';
    $sql = file_get_contents(__DIR__ . '/../database/' . $schema);
    // Strip full-line -- comments BEFORE splitting, so the first
    // statement is not mistaken for a comment block and skipped.
    $sql = preg_replace('/^--[^\n]*$/m', '', $sql);
    // Run schema statement-by-statement (PDO::exec can't do multi-statements reliably)
    $stmts = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($stmts as $s) {
        if ($s === '') continue;
        $db->exec($s);
    }
    // v2 upgrade (idempotent): section -> teacher assignment.
    // Teacher-created sections become visible only to their creator.
    try { $db->exec('ALTER TABLE sections ADD COLUMN assigned_teacher_id INT'); } catch (Throwable $e) { /* column exists */ }
    try { $db->exec('UPDATE sections SET assigned_teacher_id=created_by WHERE assigned_teacher_id IS NULL AND created_by IN (SELECT id FROM users WHERE role=\'teacher\')'); } catch (Throwable $e) { /* nothing to backfill */ }
    // v3 upgrade (idempotent): split name columns for last-name sorting.
    try { $db->exec('ALTER TABLE users ADD COLUMN lastname VARCHAR(100)'); } catch (Throwable $e) { /* exists */ }
    try { $db->exec('ALTER TABLE users ADD COLUMN firstname VARCHAR(100)'); } catch (Throwable $e) { /* exists */ }
    try { $db->exec('ALTER TABLE users ADD COLUMN mi VARCHAR(10)'); } catch (Throwable $e) { /* exists */ }
    // v4 upgrade (idempotent): essay questions + manual grading.
    try { $db->exec('ALTER TABLE attempts ADD COLUMN needs_grading SMALLINT DEFAULT 0'); } catch (Throwable $e) { /* exists */ }
    // v5 upgrade (idempotent): exam_students table for per-student assignment (remedial, make-up, accommodations).
    if ($driver === 'pgsql') {
        try { $db->exec('CREATE TABLE IF NOT EXISTS exam_students (exam_id INT NOT NULL REFERENCES exams(id) ON DELETE CASCADE, student_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE, assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (exam_id, student_id))'); } catch (Throwable $e) { /* exists */ }
    } else {
        try { $db->exec('CREATE TABLE IF NOT EXISTS exam_students (exam_id INT NOT NULL, student_id INT NOT NULL, assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (exam_id, student_id), CONSTRAINT fk_exam_students_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE, CONSTRAINT fk_exam_students_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB'); } catch (Throwable $e) { /* exists */ }
    }
    // v5b upgrade: shuffle_questions and allow_retake columns on exams.
    try { $db->exec('ALTER TABLE exams ADD COLUMN shuffle_questions SMALLINT NOT NULL DEFAULT 0'); } catch (Throwable $e) { /* exists */ }
    try { $db->exec('ALTER TABLE exams ADD COLUMN allow_retake SMALLINT NOT NULL DEFAULT 0'); } catch (Throwable $e) { /* exists */ }
    // v5c upgrade: shuffle_seed column on attempts for per-student question ordering.
    try { $db->exec('ALTER TABLE attempts ADD COLUMN shuffle_seed INT'); } catch (Throwable $e) { /* exists */ }
    // v5d upgrade: remove unique constraint on attempts (exam_id, student_id) to allow multiple attempts (retakes).
    if ($driver === 'pgsql') {
        try { $db->exec('ALTER TABLE attempts DROP CONSTRAINT IF EXISTS attempts_exam_id_student_id_key'); } catch (Throwable $e) { /* doesn't exist */ }
        try { $db->exec('ALTER TABLE attempts DROP CONSTRAINT IF EXISTS idx_attempts_exam_student'); } catch (Throwable $e) { /* doesn't exist or already a plain index */ }
    } else {
        try { $db->exec('ALTER TABLE attempts DROP INDEX uq_attempt'); } catch (Throwable $e) { /* doesn't exist */ }
    }
    // v5e upgrade: section_teachers junction table for many-to-many section-teacher relationship.
    if ($driver === 'pgsql') {
        try { $db->exec('CREATE TABLE IF NOT EXISTS section_teachers (section_id INT NOT NULL REFERENCES sections(id) ON DELETE CASCADE, teacher_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE, assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (section_id, teacher_id))'); } catch (Throwable $e) { /* exists */ }
    } else {
        try { $db->exec('CREATE TABLE IF NOT EXISTS section_teachers (section_id INT NOT NULL, teacher_id INT NOT NULL, assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (section_id, teacher_id), CONSTRAINT fk_section_teachers_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE, CONSTRAINT fk_section_teachers_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB'); } catch (Throwable $e) { /* exists */ }
    }
    // v5f upgrade: migrate existing assigned_teacher_id to section_teachers junction table.
    try {
        $st = $db->prepare('SELECT id, assigned_teacher_id FROM sections WHERE assigned_teacher_id IS NOT NULL');
        $st->execute();
        $ins_sql = ($driver === 'pgsql')
            ? 'INSERT INTO section_teachers (section_id, teacher_id) VALUES (?, ?) ON CONFLICT DO NOTHING'
            : 'INSERT IGNORE INTO section_teachers (section_id, teacher_id) VALUES (?, ?)';
        $ins = $db->prepare($ins_sql);
        while ($row = $st->fetch()) {
            $ins->execute([$row['id'], $row['assigned_teacher_id']]);
        }
    } catch (Throwable $e) { /* migration failed or no data */ }
    // v5g upgrade: add composite index on attempts(exam_id, student_id)
    try { $db->exec('CREATE INDEX idx_attempts_exam_student ON attempts(exam_id, student_id)'); } catch (Throwable $e) { /* exists */ }
    if ($driver === 'pgsql') {
        try { $db->exec('ALTER TABLE questions DROP CONSTRAINT IF EXISTS questions_qtype_check'); } catch (Throwable $e) {}
        try { $db->exec("ALTER TABLE questions ADD CONSTRAINT questions_qtype_check CHECK (qtype IN ('mcq','truefalse','identification','essay'))"); } catch (Throwable $e) {}
    } else {
        try { $db->exec("ALTER TABLE questions MODIFY qtype ENUM('mcq','truefalse','identification','essay') NOT NULL DEFAULT 'mcq'"); } catch (Throwable $e) {}
    }
    try {
        if ($driver === 'pgsql') {
            $db->exec("UPDATE users SET lastname=TRIM(SPLIT_PART(fullname, ',', 1)), firstname=TRIM(SPLIT_PART(fullname, ',', 2)) WHERE fullname LIKE '%,%' AND (lastname IS NULL OR lastname='')");
        } else {
            $db->exec("UPDATE users SET lastname=TRIM(SUBSTRING_INDEX(fullname, ',', 1)), firstname=TRIM(SUBSTRING(fullname, LOCATE(',', fullname)+1)) WHERE fullname LIKE '%,%' AND (lastname IS NULL OR lastname='')");
        }
    } catch (Throwable $e) { /* nothing to backfill */ }
    // v6 upgrade: database-backed sessions (survives Render spin-down)
    if ($driver === 'pgsql') {
        try { $db->exec('CREATE TABLE IF NOT EXISTS sessions (id VARCHAR(128) PRIMARY KEY, user_id INT NULL, data TEXT NOT NULL, last_activity INT NOT NULL)'); } catch (Throwable $e) { /* exists */ }
        try { $db->exec('CREATE INDEX IF NOT EXISTS idx_sessions_last_activity ON sessions (last_activity)'); } catch (Throwable $e) { /* exists */ }
    } else {
        try { $db->exec('CREATE TABLE IF NOT EXISTS sessions (id VARCHAR(128) NOT NULL PRIMARY KEY, user_id INT NULL, data TEXT NOT NULL, last_activity INT NOT NULL, INDEX idx_sessions_last_activity (last_activity)) ENGINE=InnoDB'); } catch (Throwable $e) { /* exists */ }
    }
}
