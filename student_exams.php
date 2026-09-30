<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('student');

$st = db()->prepare("SELECT e.*, u.fullname AS teacher_name,
    (SELECT COUNT(*) FROM questions q WHERE q.exam_id=e.id) AS qcount,
    (SELECT a.id FROM attempts a WHERE a.exam_id=e.id AND a.student_id=? AND a.submitted_at IS NOT NULL) AS done_id
    FROM exams e JOIN users u ON u.id=e.teacher_id
    WHERE e.status='published' AND (e.section_id IS NULL OR e.section_id=? OR EXISTS (SELECT 1 FROM exam_students es WHERE es.exam_id=e.id AND es.student_id=?))
    ORDER BY e.created_at DESC");
$st->execute([$user['id'], $user['section_id'], $user['id']]);
$exams = $st->fetchAll();
$title = 'Available Exams';
include __DIR__ . '/includes/header.php';
?>
<div class="card"><div class="table-wrap"><table>
  <tr><th>Exam</th><th>Teacher</th><th>Qs</th><th>Time</th><th>Status</th></tr>
  <?php foreach ($exams as $x): ?>
  <tr>
    <td><b><?= e($x['title']) ?></b><br><small class="hint"><?= e(mb_strimwidth($x['description'] ?? '', 0, 90, '…')) ?></small></td>
    <td><?= e($x['teacher_name']) ?></td><td><?= $x['qcount'] ?></td><td><?= $x['time_limit_minutes'] ?> min</td>
    <td><?php if ($x['done_id']): ?>
      <span class="badge b-published">Taken ✓</span>
      <?php if (!empty($x['allow_retake'])): ?>
        <a class="btn small ok" href="student_take.php?exam_id=<?= $x['id'] ?>">Retake</a>
      <?php endif; ?>
      <?php else: ?><a class="btn small" href="student_take.php?exam_id=<?= $x['id'] ?>">Take now</a><?php endif; ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$exams): ?><tr><td colspan="5" class="hint">No exams available for your section yet.</td></tr><?php endif; ?>
</table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
