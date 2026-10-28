<?php
// Student review of a submitted exam: shows YOUR answer per item with a
// Correct/Incorrect verdict and the points earned. The correct answer is
// NEVER shown — for mcq/truefalse/identification it is not even queried;
// the arranging-steps key is fetched only to mark each of the student's
// own steps Correct/Incorrect in its submitted position, never printed.
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
        q.option_c, q.option_d, q.points, q.steps_data,
        CASE WHEN q.qtype='arrangingsteps' THEN q.correct_answer END AS arrange_key,
        an.student_answer, an.is_correct, an.points_earned
    FROM questions q LEFT JOIN answers an ON an.question_id=q.id AND an.attempt_id=?
    WHERE q.exam_id=? ORDER BY q.sort_order, q.id");
$st->execute([$attempt_id, $attempt['exam_id']]);
$items = $st->fetchAll();

// Respect shuffle_seed for consistent review ordering
if (!empty($attempt['shuffle_seed'])) {
    mt_srand((int)$attempt['shuffle_seed']);
    shuffle($items);
}

$title = 'Review: ' . $attempt['title'];
$taken_fmt = date('m-d-Y H:i:s', strtotime($attempt['submitted_at']));
$pts_fmt = function($n) { return rtrim(rtrim(number_format((float)$n, 2), '0'), '.'); };
include __DIR__ . '/includes/header.php';
?>
<div class="card">
<!-- Print-only result slip: this ALONE appears in the PDF -->
<div class="card print-only" style="text-align:center;padding:32px 16px">
  <h2 style="margin:0 0 4px"><?= e($attempt['title']) ?></h2>
  <p style="margin:0 0 16px" class="hint">Examination Result</p>
  <p style="margin:6px 0">Student: <b><?= e($user['fullname']) ?></b></p>
  <p style="margin:6px 0">Date taken: <b><?= e($taken_fmt) ?></b></p>
  <p style="margin:6px 0">Score: <b><?= e($attempt['score']) ?>/<?= e($attempt['total']) ?> (<?= e($attempt['percentage']) ?>%)</b></p>
  <p style="margin:10px 0;font-size:1.2rem"><?= $attempt['percentage'] >= $attempt['passing_percent'] ? '<span class="badge b-published">PASSED</span>' : '<span class="badge b-closed">FAILED</span>' ?></p>
</div>
<div class="card screen-only">
  <p style="margin:0"><b>Score: <?= e($attempt['score']) ?>/<?= e($attempt['total']) ?>
    (<?= e($attempt['percentage']) ?>%)</b>
    <?= $attempt['percentage'] >= $attempt['passing_percent'] ? '<span class="badge b-published">PASSED</span>' : '<span class="badge b-closed">FAILED</span>' ?></p>
  <div class="btnrow no-print">
    <a class="btn" href="student_download.php?attempt_id=<?= $attempt_id ?>">⬇ Download as PDF</a>
    <a class="btn ghost" href="student_scores.php">Back to scores</a>
  </div>
</div>
<?php $i = 1; foreach ($items as $q):
  $ok = !empty($q['is_correct']);
  $mine = $q['student_answer'] ?? '';
  $earned = (float)($q['points_earned'] ?? 0);
  $maxpts = (float)$q['points'];
  $pending = ($q['qtype'] === 'essay' && !empty($attempt['needs_grading']));
  // Arranging steps get partial credit per correct position:
  // some points earned but not fully correct = partially correct.
  $partial = (!$ok && !$pending && $earned > 0);
?>
<div class="q">
  <h3><?= $i++ ?>. <?= e($q['question_text']) ?>
    <?php if ($q['qtype'] === 'mcq'): ?>
      <span class="hint">[mcq]</span>
    <?php elseif ($q['qtype'] === 'truefalse'): ?>
      <span class="hint">[truefalse]</span>
    <?php elseif ($q['qtype'] === 'identification'): ?>
      <span class="hint">[identification]</span>
    <?php elseif ($q['qtype'] === 'essay'): ?>
      <span class="hint">[essay]</span>
    <?php elseif ($q['qtype'] === 'arrangingsteps'): ?>
      <span class="hint">[arrangingsteps]</span>
    <?php endif; ?>
  </h3>
  <?php if ($q['qtype'] === 'mcq'):
    $mine_letter = strtoupper(substr($mine, 0, 1)); ?>
    <?php foreach (['A' => $q['option_a'], 'B' => $q['option_b'], 'C' => $q['option_c'], 'D' => $q['option_d']] as $L => $opt): ?>
      <?php if ($opt !== null && $opt !== ''): ?>
      <div class="opt"<?= $mine_letter === $L ? ' style="border-width:2px;font-weight:700;' . ($ok ? 'border-color:#16a34a;background:#f0fdf4' : 'border-color:#dc2626;background:#fef2f2') . '"' : '' ?>>
        <b><?= $L ?>.</b> <?= e($opt) ?>
        <?php if ($mine_letter === $L): ?><small class="hint">— your answer <?= $ok ? '✔' : '✘' ?></small><?php endif; ?>
      </div>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php elseif ($q['qtype'] === 'arrangingsteps'):
    $steps = json_decode($q['steps_data'] ?? '[]', true);
    if (!is_array($steps)) $steps = [];
    // Mark each step the student placed using the same position-by-position
    // comparison the grader used. The correct sequence itself is never shown.
    $correct_seq = array_values(array_filter(array_map('trim', explode('-', trim((string)($q['arrange_key'] ?? ''))))));
    $student_order = array_values(array_filter(array_map('trim', explode('-', (string)$mine))));
  ?>
    <p style="margin:6px 0"><b>Your answer (in the order you placed the steps):</b></p>
    <?php foreach ($student_order as $index => $step_num):
      $step_text = '';
      foreach ($steps as $s) {
        if ((string)($s['step'] ?? '') === $step_num || ($s['text'] ?? '') === $step_num) {
          $step_text = $s['text'] ?? '';
          break;
        }
      }
      $step_ok = isset($correct_seq[$index]) && $correct_seq[$index] === $step_num;
    ?>
      <div class="opt" style="border-width:2px;<?= $step_ok ? 'border-color:#16a34a;background:#f0fdf4' : 'border-color:#dc2626;background:#fef2f2' ?>">
        <b>Step <?= e($step_num) ?>:</b> <?= e($step_text !== '' ? $step_text : $step_num) ?>
        <?= $step_ok ? '<span class="badge b-published">✔ Correct</span>' : '<span class="badge b-closed">✘ Incorrect</span>' ?>
      </div>
    <?php endforeach; ?>
    <?php if (empty($student_order)): ?><div class="opt"><i>No answer provided</i></div><?php endif; ?>
  <?php else: ?>
    <p style="margin:6px 0">Your answer: <?php if ($pending): ?><b><?= e($mine !== '' && $mine !== null ? $mine : '(blank)') ?></b><?php else: ?><b style="color:<?= $ok ? '#166534' : '#991b1b' ?>"><?= e($mine !== '' && $mine !== null ? $mine : '(blank)') ?><?= $ok ? ' ✔' : ' ✘' ?></b><?php endif; ?></p>
  <?php endif; ?>
  <p style="margin:10px 0 0">
    <?php if ($pending): ?>
      <span class="badge b-draft">FOR CHECKING</span>
    <?php elseif ($ok): ?>
      <span class="badge b-published">✔ CORRECT</span>
    <?php elseif ($partial): ?>
      <span class="badge b-draft">PARTIALLY CORRECT</span>
    <?php else: ?>
      <span class="badge b-closed">✘ INCORRECT</span>
    <?php endif; ?>
    <?php if (!$pending): ?>
      <small class="hint"><?= e($pts_fmt($earned)) ?>/<?= e($pts_fmt($maxpts)) ?> point<?= $maxpts == 1 ? '' : 's' ?></small>
    <?php endif; ?>
  </p>
</div>
<?php endforeach; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>