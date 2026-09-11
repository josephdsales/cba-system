<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('student');

$st = db()->prepare("SELECT a.*, e.title, e.passing_percent FROM attempts a JOIN exams e ON e.id=a.exam_id
    WHERE a.student_id=? AND a.submitted_at IS NOT NULL ORDER BY a.submitted_at DESC");
$st->execute([$user['id']]);
$rows = $st->fetchAll();
$title = 'My Scores';
include __DIR__ . '/includes/header.php';
?>
<div class="card"><div class="table-wrap"><table>
  <tr><th>Exam</th><th>Score</th><th>%</th><th>Result</th><th>Date</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= e($r['title']) ?></td><td><?= e($r['score']) ?>/<?= e($r['total']) ?></td>
    <td><b><?= e($r['percentage']) ?>%</b></td>
    <td><?= $r['percentage'] >= $r['passing_percent'] ? '<span class="badge b-published">PASSED</span>' : '<span class="badge b-closed">FAILED</span>' ?></td>
    <td><?= e($r['submitted_at']) ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="hint">No scores yet. <a href="student_exams.php">Take an exam</a>.</td></tr><?php endif; ?>
</table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
