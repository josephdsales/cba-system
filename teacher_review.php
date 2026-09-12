<?php
// Teacher review of ONE student's submitted attempt.
// Unlike the student view, the correct answer IS shown here.
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('teacher');

$attempt_id = (int)($_GET['attempt_id'] ?? 0);
$st = db()->prepare("SELECT a.*, e.title, e.teacher_id, u.fullname AS student_name, s.name AS section_name
    FROM attempts a JOIN exams e ON e.id=a.exam_id
    JOIN users u ON u.id=a.student_id LEFT JOIN sections s ON s.id=u.section_id
    WHERE a.id=? AND e.teacher_id=? AND a.submitted_at IS NOT NULL");
$st->execute([$attempt_id, $user['id']]);
$attempt = $st->fetch();
if (!$attempt) { set_flash('Review not available.'); header('Location: teacher_results.php'); exit; }

$st = db()->prepare("SELECT q.*, an.student_answer, an.is_correct, an.points_earned
    FROM questions q LEFT JOIN answers an ON an.question_id=q.id AND an.attempt_id=?
    WHERE q.exam_id=? ORDER BY q.sort_order, q.id");
$st->execute([$attempt_id, $attempt['exam_id']]);
$items = $st->fetchAll();

$title = 'Review: ' . $attempt['student_name'];
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <p style="margin:0"><b><?= e($attempt['title']) ?></b> — <?= e($attempt['student_name']) ?>
    <small class="hint">(<?= e($attempt['section_name'] ?? '—') ?>)</small><br>
    Score: <b><?= e($attempt['score']) ?>/<?= e($attempt['total']) ?> (<?= e($attempt['percentage']) ?>%)</b>
    · submitted <?= e(date('m-d-Y H:i:s', strtotime($attempt['submitted_at']))) ?></p>
  <div class="btnrow">
    <a class="btn" href="teacher_download.php?attempt_id=<?= $attempt_id ?>">⬇ Download as PDF</a>
    <a class="btn ghost" href="teacher_results.php?exam_id=<?= $attempt['exam_id'] ?>">Back to results</a>
  </div>
</div>
<?php $i = 1; foreach ($items as $q):
  $ok = !empty($q['is_correct']);
  $mine = $q['student_answer'] ?? '';
?>
<div class="q">
  <h3><?= $i++ ?>. <?= e($q['question_text']) ?>
    <?= $ok ? '<span class="badge b-published">✓ Correct</span>' : '<span class="badge b-closed">✗ Incorrect</span>' ?>
    <small class="hint"><?= e($q['points_earned']) ?>/<?= e($q['points']) ?> pt(s)</small></h3>
  <?php if ($q['qtype'] === 'mcq'): ?>
    <?php foreach (['A' => $q['option_a'], 'B' => $q['option_b'], 'C' => $q['option_c'], 'D' => $q['option_d']] as $L => $opt): ?>
      <?php if ($opt !== null && $opt !== ''): ?>
        <div class="opt">
          <b><?= $L ?>.</b> <?= e($opt) ?>
          <?php if (strtoupper($q['correct_answer']) === $L): ?><span class="badge b-published">correct answer</span><?php endif; ?>
          <?php if (strtoupper(substr($mine, 0, 1)) === $L): ?><small class="hint">— student's answer</small><?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php else: ?>
    <p style="margin:6px 0">Correct answer: <b><?= e($q['correct_answer']) ?></b><br>
    Student's answer: <b><?= e($mine !== '' && $mine !== null ? $mine : '(blank)') ?></b></p>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
