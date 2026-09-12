<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('student');

$exam_id = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
$st = db()->prepare("SELECT * FROM exams WHERE id=? AND status='published' AND (section_id IS NULL OR section_id=?)");
$st->execute([$exam_id, $user['section_id']]);
$exam = $st->fetch();
if (!$exam) { set_flash('Exam not available.'); header('Location: student_exams.php'); exit; }

// block retake
$st = db()->prepare('SELECT * FROM attempts WHERE exam_id=? AND student_id=? AND submitted_at IS NOT NULL');
$st->execute([$exam_id, $user['id']]);
if ($st->fetch()) { set_flash('You already submitted this exam.'); header('Location: student_scores.php'); exit; }

$st = db()->prepare('SELECT * FROM questions WHERE exam_id=? ORDER BY sort_order, id');
$st->execute([$exam_id]); $questions = $st->fetchAll();
if (!$questions) { set_flash('This exam has no questions yet.'); header('Location: student_exams.php'); exit; }

// ensure attempt row (tracks start time for timer)
$st = db()->prepare('SELECT * FROM attempts WHERE exam_id=? AND student_id=?');
$st->execute([$exam_id, $user['id']]);
$attempt = $st->fetch();
if (!$attempt) {
    $total = array_sum(array_column($questions, 'points'));
    $st = db()->prepare('INSERT INTO attempts (exam_id, student_id, total) VALUES (?, ?, ?)');
    $st->execute([$exam_id, $user['id'], $total]);
    $attempt_id = (int)db()->lastInsertId();
    $started = time();
} else {
    $attempt_id = (int)$attempt['id'];
    $started = strtotime($attempt['started_at']);
}
$elapsed = time() - $started;
$remain = max(1, $exam['time_limit_minutes'] * 60 - $elapsed);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $score = 0; $total = array_sum(array_column($questions, 'points'));
    $needs_grading = 0;
    $ins = db()->prepare('INSERT INTO answers (attempt_id, question_id, student_answer, is_correct, points_earned) VALUES (?, ?, ?, ?, ?)');
    db()->prepare('DELETE FROM answers WHERE attempt_id=?')->execute([$attempt_id]);
    foreach ($questions as $q) {
        $ans = trim($_POST['q_' . $q['id']] ?? '');
        $correct = false;
        if ($q['qtype'] === 'essay') { $needs_grading = 1; }
        if ($q['qtype'] === 'mcq') $correct = (strtoupper(substr($ans, 0, 1)) === strtoupper($q['correct_answer']));
        elseif ($q['qtype'] === 'truefalse') $correct = (strtolower($ans) === strtolower($q['correct_answer']));
        else $correct = (strtolower($ans) === strtolower(trim($q['correct_answer'])));
        $earned = $correct ? $q['points'] : 0;
        $score += $earned;
        $ins->execute([$attempt_id, $q['id'], $ans !== '' ? $ans : null, $correct ? 1 : 0, $earned]);
    }
    $pct = $total > 0 ? round($score / $total * 100, 2) : 0;
    $st = db()->prepare('UPDATE attempts SET score=?, total=?, percentage=?, submitted_at=NOW(), needs_grading=? WHERE id=?');
    $st->execute([$score, $total, $pct, $needs_grading, $attempt_id]);
    set_flash($needs_grading ? "Exam submitted. Partial score: $score/$total — essay answers are for checking."
        : "Exam submitted. Your score: $score/$total ($pct%).");
    header('Location: student_scores.php'); exit;
}

$title = $exam['title'];
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <p class="hint"><?= e($exam['description'] ?? '') ?></p>
  <span class="timer" id="exam-timer" data-seconds="<?= $remain ?>">⏳ --:--</span>
  <p class="hint">One submission only. Timer auto-submits when time runs out.</p>
</div>
<form method="post" id="exam-form">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="exam_id" value="<?= $exam_id ?>">
  <?php $i = 1; foreach ($questions as $q): ?>
  <div class="q">
    <h3><?= $i++ ?>. <?= e($q['question_text']) ?> <small class="hint">(<?= $q['points'] ?> pt)</small></h3>
    <?php if ($q['qtype'] === 'mcq'): ?>
      <?php foreach (['A' => $q['option_a'], 'B' => $q['option_b'], 'C' => $q['option_c'], 'D' => $q['option_d']] as $L => $opt): ?>
        <?php if ($opt !== null && $opt !== ''): ?>
        <label class="opt"><input type="radio" name="q_<?= $q['id'] ?>" value="<?= $L ?>" required> <b><?= $L ?>.</b> <?= e($opt) ?></label>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php elseif ($q['qtype'] === 'truefalse'): ?>
      <label class="opt"><input type="radio" name="q_<?= $q['id'] ?>" value="True" required> True</label>
      <label class="opt"><input type="radio" name="q_<?= $q['id'] ?>" value="False" required> False</label>
    <?php elseif ($q['qtype'] === 'essay'): ?>
      <textarea name="q_<?= $q['id'] ?>" placeholder="Write your answer here" required rows="5"></textarea>
    <?php else: ?>
      <input type="text" name="q_<?= $q['id'] ?>" placeholder="Type your answer" required>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <div class="card"><button class="btn ok" type="submit" onclick="return confirm('Submit your answers now?')">Submit Exam</button></div>
</form>
<?php include __DIR__ . '/includes/footer.php'; ?>
