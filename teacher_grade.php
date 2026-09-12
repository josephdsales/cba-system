<?php
// Teacher manual grading: lists submitted attempts with essay answers
// awaiting checking, per essay awards points, then finalizes the score.
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('teacher');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $attempt_id = (int)($_POST['attempt_id'] ?? 0);
    $st = db()->prepare("SELECT a.*, e.teacher_id FROM attempts a JOIN exams e ON e.id=a.exam_id
        WHERE a.id=? AND e.teacher_id=? AND a.submitted_at IS NOT NULL");
    $st->execute([$attempt_id, $user['id']]);
    $attempt = $st->fetch();
    if (!$attempt) { set_flash('Attempt not found.'); header('Location: teacher_grade.php'); exit; }
    $st = db()->prepare("SELECT an.id, an.question_id, q.points FROM answers an
        JOIN questions q ON q.id=an.question_id WHERE an.attempt_id=? AND q.qtype='essay'");
    $st->execute([$attempt_id]);
    $essays = $st->fetchAll();
    $upd = db()->prepare('UPDATE answers SET points_earned=?, is_correct=? WHERE id=? AND attempt_id=?');
    foreach ($essays as $es) {
        $pts = max(0, min((int)$es['points'], (int)($_POST['pts_' . $es['id']] ?? 0)));
        $upd->execute([$pts, $pts > 0 ? 1 : 0, $es['id'], $attempt_id]);
    }
    $st = db()->prepare('SELECT COALESCE(SUM(points_earned),0) s FROM answers WHERE attempt_id=?');
    $st->execute([$attempt_id]); $score = $st->fetch()['s'];
    $total = (float)$attempt['total'];
    $pct = $total > 0 ? round($score / $total * 100, 2) : 0;
    $st = db()->prepare('UPDATE attempts SET score=?, percentage=?, needs_grading=0 WHERE id=?');
    $st->execute([$score, $pct, $attempt_id]);
    set_flash("Grading saved. Final score: $score/$total ($pct%).");
    header('Location: teacher_grade.php'); exit;
}

$st = db()->prepare("SELECT a.*, e.title, u.fullname AS student_name, s.name AS section_name
    FROM attempts a JOIN exams e ON e.id=a.exam_id
    JOIN users u ON u.id=a.student_id LEFT JOIN sections s ON s.id=u.section_id
    WHERE e.teacher_id=? AND a.submitted_at IS NOT NULL AND a.needs_grading=1
    ORDER BY a.submitted_at");
$st->execute([$user['id']]);
$pending = $st->fetchAll();

$grade = null; $essays = [];
if (isset($_GET['attempt_id'])) {
    $st = db()->prepare("SELECT a.*, e.title, u.fullname AS student_name FROM attempts a
        JOIN exams e ON e.id=a.exam_id JOIN users u ON u.id=a.student_id
        WHERE a.id=? AND e.teacher_id=? AND a.submitted_at IS NOT NULL");
    $st->execute([(int)$_GET['attempt_id'], $user['id']]);
    $grade = $st->fetch();
    if ($grade) {
        $st = db()->prepare("SELECT q.question_text, q.points, an.id AS aid, an.student_answer, an.points_earned
            FROM questions q LEFT JOIN answers an ON an.question_id=q.id AND an.attempt_id=?
            WHERE q.exam_id=? AND q.qtype='essay' ORDER BY q.sort_order, q.id");
        $st->execute([$grade['id'], $grade['exam_id']]);
        $essays = $st->fetchAll();
    }
}

$title = 'Grade Essays';
include __DIR__ . '/includes/header.php';
?>
<?php if ($grade): ?>
<div class="card">
  <h3 style="margin-top:0"><?= e($grade['title']) ?> — <?= e($grade['student_name']) ?></h3>
  <p class="hint">Partial auto-score: <?= e($grade['score']) ?>/<?= e($grade['total']) ?>. Award points per essay, then save.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="attempt_id" value="<?= $grade['id'] ?>">
    <?php $i = 1; foreach ($essays as $es): ?>
    <div class="q">
      <h3><?= $i++ ?>. <?= e($es['question_text']) ?> <small class="hint">(max <?= $es['points'] ?> pts)</small></h3>
      <p style="white-space:pre-wrap;background:#f8fafc;border:1px solid var(--line);border-radius:8px;padding:10px"><?= e($es['student_answer'] ?? '(blank)') ?></p>
      <label>Points (0–<?= $es['points'] ?>)</label>
      <input type="number" name="pts_<?= $es['aid'] ?>" min="0" max="<?= $es['points'] ?>" value="<?= e($es['points_earned'] ?? 0) ?>" required>
    </div>
    <?php endforeach; ?>
    <?php if (!$essays): ?><p class="hint">No essay questions in this attempt.</p><?php endif; ?>
    <div class="btnrow"><button class="btn ok" type="submit">Save grading</button>
    <a class="btn ghost" href="teacher_grade.php">Back</a></div>
  </form>
</div>
<?php else: ?>
<div class="card"><div class="table-wrap"><table>
  <tr><th>Exam</th><th>Student</th><th>Section</th><th>Auto-score</th><th>Submitted</th><th></th></tr>
  <?php foreach ($pending as $p): ?>
  <tr>
    <td><?= e($p['title']) ?></td><td><?= e($p['student_name']) ?></td><td><?= e($p['section_name'] ?? '—') ?></td>
    <td><?= e($p['score']) ?>/<?= e($p['total']) ?></td><td><?= e(date('m-d-Y H:i:s', strtotime($p['submitted_at']))) ?></td>
    <td><a class="btn small" href="teacher_grade.php?attempt_id=<?= $p['id'] ?>">Grade</a></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$pending): ?><tr><td colspan="6" class="hint">Nothing awaiting checking. 🎉</td></tr><?php endif; ?>
</table></div></div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
