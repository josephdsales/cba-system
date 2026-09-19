<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_login();
refresh_session_user((int)$user['id']);
$user = current_user();

$title = 'Dashboard';
include __DIR__ . '/includes/header.php';

try {
if ($user['role'] === 'admin') {
    $t = db()->query("SELECT COUNT(*) c FROM users WHERE role='teacher'")->fetch()['c'];
    $s = db()->query("SELECT COUNT(*) c FROM users WHERE role='student'")->fetch()['c'];
    $e = db()->query("SELECT COUNT(*) c FROM exams")->fetch()['c'];
    $sec = db()->query("SELECT COUNT(*) c FROM sections")->fetch()['c'];
    ?>
    <div class="grid two">
      <div class="card stat"><div class="n"><?= $t ?></div><div class="l">Teachers</div><div class="btnrow" style="justify-content:center"><a class="btn small" href="admin_teachers.php">Manage</a></div></div>
      <div class="card stat"><div class="n"><?= $s ?></div><div class="l">Students</div><div class="btnrow" style="justify-content:center"><a class="btn small" href="admin_students.php">Manage + Reset Passwords</a></div></div>
      <div class="card stat"><div class="n"><?= $sec ?></div><div class="l">Sections</div><div class="btnrow" style="justify-content:center"><a class="btn small" href="admin_sections.php">Manage</a></div></div>
      <div class="card stat"><div class="n"><?= $e ?></div><div class="l">Exams</div><div class="btnrow" style="justify-content:center"><a class="btn small" href="admin_reports.php">Reports: scores, highest, lowest</a></div></div>
    </div>
    <?php
} elseif ($user['role'] === 'teacher') {
    $st = db()->prepare('SELECT COUNT(*) c FROM exams WHERE teacher_id=?');
    $st->execute([$user['id']]); $e = $st->fetch()['c'];
    $sec = db()->query('SELECT COUNT(*) c FROM sections')->fetch()['c'];
    $st = db()->prepare("SELECT COUNT(*) c FROM attempts a JOIN exams e ON e.id=a.exam_id WHERE e.teacher_id=? AND a.submitted_at IS NOT NULL AND a.needs_grading=1");
    $st->execute([$user['id']]); $pend = $st->fetch()['c'];
    ?>
    <div class="grid two">
      <div class="card"><h3 style="margin-top:0">📝 My Exams (<?= $e ?>)</h3>
        <p class="hint">Create exams, add questions manually, or import from Word (.docx).</p>
        <div class="btnrow"><a class="btn" href="teacher_exams.php">Open Exams</a><a class="btn ghost" href="teacher_exams.php?action=new">+ New Exam</a><a class="btn ghost" href="teacher_grade.php">Grading (<?= $pend ?>)</a></div></div>
      <div class="card"><h3 style="margin-top:0">👥 Sections (<?= $sec ?>)</h3>
        <p class="hint">Add, edit, delete sections used at student registration.</p>
        <div class="btnrow"><a class="btn" href="teacher_sections.php">Manage Sections</a><a class="btn ghost" href="teacher_results.php">View Results</a></div></div>
    </div>
    <?php
} else {
    $st = db()->prepare("SELECT COUNT(*) c FROM exams WHERE status='published' AND (section_id IS NULL OR section_id=?)");
    $st->execute([$user['section_id']]); $open = $st->fetch()['c'];
    $st = db()->prepare('SELECT COUNT(*) c FROM attempts WHERE student_id=? AND submitted_at IS NOT NULL');
    $st->execute([$user['id']]); $taken = $st->fetch()['c'];
    ?>
    <div class="card">
      <p>Hi <b><?= e($user['fullname']) ?></b>! Section: <b><?= e($user['section_name'] ?? '—') ?></b></p>
    </div>
    <div class="grid two">
      <div class="card stat"><div class="n"><?= $open ?></div><div class="l">Available exams</div><div class="btnrow" style="justify-content:center"><a class="btn small" href="student_exams.php">Take Exam</a></div></div>
      <div class="card stat"><div class="n"><?= $taken ?></div><div class="l">Exams taken</div><div class="btnrow" style="justify-content:center"><a class="btn small" href="student_scores.php">My Scores</a></div></div>
    </div>
    <?php
}
} catch (PDOException $ex) {
    echo '<div class="card"><b>Database error:</b> ' . e($ex->getMessage()) . '</div>';
}
include __DIR__ . '/includes/footer.php';
