<?php
// Teacher: search a student by name and view all their submitted attempts
// across this teacher's own exams. Click "Review" to see the per-item
// breakdown, or "PDF" to download a single-attempt report.
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/schema.php';
$user = require_role('teacher');

$q = trim($_GET['q'] ?? '');
$students = [];
$attempts = [];
$selStudent = null;

if ($q !== '') {
    // Find students matching last name OR first name (case-insensitive),
    // but only if they have at least one submitted attempt in this
    // teacher's own exams.
    $st = db()->prepare("SELECT u.id, u.fullname, u.username, u.lastname, u.firstname,
        s.name AS section_name, COUNT(a.id) AS attempt_count
        FROM users u
        LEFT JOIN sections s ON s.id = u.section_id
        LEFT JOIN attempts a ON a.student_id = u.id AND a.submitted_at IS NOT NULL
        LEFT JOIN exams e ON e.id = a.exam_id
        WHERE u.role = 'student'
          AND e.teacher_id = ?
          AND (LOWER(u.lastname) LIKE LOWER(?) OR LOWER(u.firstname) LIKE LOWER(?))
        GROUP BY u.id
        ORDER BY u.lastname, u.firstname");
    $st->execute([$user['id'], '%' . $q . '%', '%' . $q . '%']);
    $students = $st->fetchAll();

    // If a student is selected, load their attempts for this teacher's exams.
    $student_id = (int)($_GET['student_id'] ?? 0);
    if ($student_id > 0) {
        $st = db()->prepare("SELECT u.id, u.fullname, u.username, u.lastname, u.firstname,
            s.name AS section_name FROM users u
            LEFT JOIN sections s ON s.id = u.section_id
            WHERE u.id = ? AND u.role = 'student'");
        $st->execute([$student_id]);
        $selStudent = $st->fetch();
        if ($selStudent) {
            $st = db()->prepare("SELECT a.id, a.score, a.total, a.percentage, a.submitted_at,
                e.title AS exam_title, e.id AS exam_id
                FROM attempts a
                JOIN exams e ON e.id = a.exam_id
                WHERE a.student_id = ? AND e.teacher_id = ? AND a.submitted_at IS NOT NULL
                ORDER BY a.submitted_at DESC");
            $st->execute([$student_id, $user['id']]);
            $attempts = $st->fetchAll();
        }
    }
}

$title = 'Individual Student Results';
include __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:860px">
  <h3 style="margin-top:0">👤 Individual Student Results</h3>
  <p class="hint">Search a student by last name or first name, then view all their submitted attempts for your exams.</p>

  <form method="get" style="border-top:1px solid var(--line);padding-top:16px;margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div style="flex:1;min-width:240px">
      <label style="display:block;margin-bottom:4px">Student name (last or first)</label>
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="e.g. cruz or juan" required>
    </div>
    <button class="btn" type="submit">Search</button>
  </form>

  <?php if ($q !== '' && !$students): ?>
    <p class="hint">No students found matching "<?= e($q) ?>" who have submitted attempts for your exams.</p>
  <?php endif; ?>

  <?php if ($students): ?>
    <div style="margin-top:12px">
      <h4 style="margin:0 0 8px 0">Matching students (<?= count($students) ?>)</h4>
      <table style="width:100%;border-collapse:collapse;font-size:.9rem">
        <thead>
          <tr><th style="text-align:left;padding:8px">Name</th><th style="text-align:left;padding:8px">Username</th><th style="text-align:center;padding:8px">Section</th><th style="text-align:center;padding:8px">Attempts</th><th style="text-align:left;padding:8px">Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($students as $s): ?>
          <tr style="border-bottom:1px solid var(--line)">
            <td style="padding:8px"><?= e($s['fullname']) ?></td>
            <td style="padding:8px"><?= e($s['username']) ?></td>
            <td style="text-align:center;padding:8px"><?= e($s['section_name'] ?? '—') ?></td>
            <td style="text-align:center;padding:8px"><b><?= (int)$s['attempt_count'] ?></b></td>
            <td style="padding:8px">
              <a class="btn small" href="teacher_student_results.php?q=<?= urlencode($q) ?>&student_id=<?= $s['id'] ?>">View attempts</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($selStudent && $attempts !== null): ?>
    <div class="card" style="margin-top:20px">
      <h3 style="margin-top:0">Attempts: <?= e($selStudent['fullname']) ?>
        <small class="hint">(<?= e($selStudent['username']) ?> · <?= e($selStudent['section_name'] ?? '—') ?>)</small></h3>
      <table style="width:100%;border-collapse:collapse;font-size:.9rem;margin-top:10px">
        <thead>
          <tr><th style="text-align:left;padding:8px">Exam</th><th style="text-align:center;padding:8px">Score</th><th style="text-align:center;padding:8px">Submitted</th><th style="text-align:left;padding:8px">Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($attempts as $a): ?>
          <tr style="border-bottom:1px solid var(--line)">
            <td style="padding:8px"><?= e($a['exam_title']) ?></td>
            <td style="text-align:center;padding:8px"><b><?= e($a['score']) ?>/<?= e($a['total']) ?></b></td>
            <td style="text-align:center;padding:8px"><?= e(date('m-d-Y H:i:s', strtotime($a['submitted_at']))) ?></td>
            <td style="padding:8px">
              <a class="btn small" href="teacher_review.php?attempt_id=<?= $a['id'] ?>">🔍 Review</a>
              <a class="btn small ghost" href="teacher_download.php?attempt_id=<?= $a['id'] ?>">📄 PDF</a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$attempts): ?>
          <tr><td colspan="4" class="hint" style="padding:16px;text-align:center">No submitted attempts yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="btnrow" style="margin-top:10px">
        <a class="btn ghost" href="teacher_student_results.php?q=<?= urlencode($q) ?>">← Back to search results</a>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
