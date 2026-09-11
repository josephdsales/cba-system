<?php
// Student review of a submitted exam: shows YOUR answer per item marked
// correct/incorrect, with points. The correct answer is NEVER shown
// (not even in the HTML source — it is simply not queried).
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('student');

$attempt_id = (int)($_GET['attempt_id'] ?? 0);
$st = db()->prepare("SELECT a.*, e.title, e.description, e.passing_percent FROM attempts a
    JOIN exams e ON e.id=a.exam_id
    WHERE a.id=? AND a.student_id=? AND a.submitted_at IS NOT NULL");
$st->execute([$attempt_id, $user['id']]);
$attempt = $st->fetch();
if (!$attempt) { set_flash('Review not available.'); header('Location: student_scores.php'); exit; }

$st = db()->prepare("SELECT q.id, q.question_text, q.qtype, q.option_a, q.option_b,
        q.option_c, q.option_d, q.points, an.student_answer, an.is_correct, an.points_earned
    FROM questions q LEFT JOIN answers an ON an.question_id=q.id AND an.attempt_id=?
    WHERE q.exam_id=? ORDER BY q.sort_order, q.id");
$st->execute([$attempt_id, $attempt['exam_id']]);
$items = $st->fetchAll();

$title = 'Review: ' . $attempt['title'];
include __DIR__ . '/includes/header.php';
?>
<!-- Print-only result slip: this ALONE appears in the PDF -->
<div class="card print-only" style="text-align:center;padding:32px 16px">
  <h2 style="margin:0 0 4px"><?= e($attempt['title']) ?></h2>
  <p style="margin:0 0 16px" class="hint">Examination Result</p>
  <p style="margin:6px 0">Student: <b><?= e($user['fullname']) ?></b></p>
  <p style="margin:6px 0">Date taken: <b><?= e($attempt['submitted_at']) ?></b></p>
  <p style="margin:6px 0">Score: <b><?= e($attempt['score']) ?>/<?= e($attempt['total']) ?> (<?= e($attempt['percentage']) ?>%)</b></p>
  <p style="margin:10px 0;font-size:1.2rem"><?= $attempt['percentage'] >= $attempt['passing_percent'] ? '<span class="badge b-published">PASSED</span>' : '<span class="badge b-closed">FAILED</span>' ?></p>
</div>
<div class="card screen-only">
  <p style="margin:0"><b>Score: <?= e($attempt['score']) ?>/<?= e($attempt['total']) ?>
    (<?= e($attempt['percentage']) ?>%)</b>
    <?= $attempt['percentage'] >= $attempt['passing_percent'] ? '<span class="badge b-published">PASSED</span>' : '<span class="badge b-closed">FAILED</span>' ?>
    <br><small class="hint">Submitted <?= e($attempt['submitted_at']) ?> · ✓ = your answer was correct, ✗ = incorrect (correct answers are not shown)</small></p>
  <div class="btnrow no-print">
    <button class="btn" type="button" onclick="window.print()">⬇ Download as PDF</button>
    <a class="btn ghost" href="student_scores.php">Back to scores</a>
  </div>
  <p class="hint no-print">Tip: the PDF contains only the result slip above (title, date, score, result). In the print dialog choose <b>Save as PDF</b>.</p>
</div>
<?php $i = 1; foreach ($items as $q):
  $ok = !empty($q['is_correct']);
  $mine = $q['student_answer'] ?? '';
?>
<div class="q screen-only">
  <h3><?= $i++ ?>. <?= e($q['question_text']) ?>
    <?= $ok ? '<span class="badge b-published">✓ Correct</span>' : '<span class="badge b-closed">✗ Incorrect</span>' ?>
    <small class="hint"><?= e($q['points_earned']) ?>/<?= e($q['points']) ?> pt(s)</small></h3>
  <?php if ($q['qtype'] === 'mcq'): ?>
    <?php foreach (['A' => $q['option_a'], 'B' => $q['option_b'], 'C' => $q['option_c'], 'D' => $q['option_d']] as $L => $opt): ?>
      <?php if ($opt !== null && $opt !== ''): ?>
        <div class="opt" <?= strtoupper(substr($mine, 0, 1)) === $L ? 'style="border-width:2px;font-weight:700"' : '' ?>>
          <b><?= $L ?>.</b> <?= e($opt) ?>
          <?= strtoupper(substr($mine, 0, 1)) === $L ? '<small class="hint">— your answer</small>' : '' ?>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php else: ?>
    <p style="margin:6px 0">Your answer: <b><?= e($mine !== '' && $mine !== null ? $mine : '(blank)') ?></b></p>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
