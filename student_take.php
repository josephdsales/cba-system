<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('student');

$exam_id = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
$st = db()->prepare("SELECT * FROM exams WHERE id=? AND status='published' AND (section_id IS NULL OR section_id=? OR EXISTS (SELECT 1 FROM exam_students es WHERE es.exam_id=exams.id AND es.student_id=?))");
$st->execute([$exam_id, $user['section_id'], $user['id']]);
$exam = $st->fetch();
if (!$exam) { set_flash('Exam not available.'); header('Location: student_exams.php'); exit; }

$st = db()->prepare('SELECT * FROM questions WHERE exam_id=? ORDER BY sort_order, id');
$st->execute([$exam_id]); $questions = $st->fetchAll();
if (!$questions) { set_flash('This exam has no questions yet.'); header('Location: student_exams.php'); exit; }

$st = db()->prepare('SELECT * FROM attempts WHERE exam_id=? AND student_id=?');
$st->execute([$exam_id, $user['id']]);
$attempt = $st->fetch();

$allow_retake = !empty($exam['allow_retake']);
$shuffle = !empty($exam['shuffle_questions']);

// Check if this exam was assigned via remedial and student already has a submitted attempt
$is_remedial = false;
if ($allow_retake) {
    $st = db()->prepare('SELECT 1 FROM exam_students WHERE exam_id=? AND student_id=?');
    $st->execute([$exam_id, $user['id']]);
    if ($st->fetch()) {
        $is_remedial = true;
        // Check if student already has a submitted attempt on this remedial exam
        $st = db()->prepare('SELECT 1 FROM attempts WHERE exam_id=? AND student_id=? AND submitted_at IS NOT NULL');
        $st->execute([$exam_id, $user['id']]);
        if ($st->fetch()) {
            set_flash('You have already taken this remedial exam. Only one attempt allowed.'); 
            header('Location: student_scores.php'); exit;
        }
    }
}

if ($attempt && $attempt['submitted_at'] !== null) {
    if (!$allow_retake) {
        set_flash('You already submitted this exam.'); header('Location: student_scores.php'); exit;
    }
    // If remedial and already attempted, we already blocked above
    if ($is_remedial) {
        set_flash('You have already taken this remedial exam. Only one attempt allowed.'); 
        header('Location: student_scores.php'); exit;
    }
    // retake allowed: create NEW attempt, keep old one intact
    $total = array_sum(array_column($questions, 'points'));
    $seed = $shuffle ? mt_rand(1, 2147483647) : null;
    $st = db()->prepare('INSERT INTO attempts (exam_id, student_id, total, shuffle_seed) VALUES (?, ?, ?, ?)');
    $st->execute([$exam_id, $user['id'], $total, $seed]);
    $attempt_id = (int)db()->lastInsertId();
    $started = time();
} else {
    if (!$attempt) {
        $total = array_sum(array_column($questions, 'points'));
        $seed = $shuffle ? mt_rand(1, 2147483647) : null;
        $st = db()->prepare('INSERT INTO attempts (exam_id, student_id, total, shuffle_seed) VALUES (?, ?, ?, ?)');
        $st->execute([$exam_id, $user['id'], $total, $seed]);
        $attempt_id = (int)db()->lastInsertId();
        $started = time();
    } else {
        $attempt_id = (int)$attempt['id'];
        $started = strtotime($attempt['started_at']);
        if ($shuffle && empty($attempt['shuffle_seed'])) {
            $seed = mt_rand(1, 2147483647);
            db()->prepare('UPDATE attempts SET shuffle_seed=? WHERE id=?')->execute([$seed, $attempt_id]);
        }
    }
}

$elapsed = time() - $started;
$remain = max(1, $exam['time_limit_minutes'] * 60 - $elapsed);

if ($shuffle) {
    // Use the current attempt's shuffle_seed (new attempt for retakes)
    $current_attempt = db()->prepare('SELECT shuffle_seed FROM attempts WHERE id=?');
    $current_attempt->execute([$attempt_id]);
    $seed = $current_attempt->fetchColumn() ?? mt_rand(1, 2147483647);
    mt_srand($seed);
    shuffle($questions);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $score = 0; $total = array_sum(array_column($questions, 'points'));
    $needs_grading = 0;
    $ins = db()->prepare('INSERT INTO answers (attempt_id, question_id, student_answer, is_correct, points_earned) VALUES (?, ?, ?, ?, ?)');
    db()->prepare('DELETE FROM answers WHERE attempt_id=?')->execute([$attempt_id]);
    foreach ($questions as $q) {
        $ans = trim($_POST['q_' . $q['id']] ?? '');
        $correct = false;
        if ($q['qtype'] === 'essay') {
            $needs_grading = 1;
            $earned = 0; // Essays get 0 until teacher grades
        } elseif ($q['qtype'] === 'mcq') {
            $correct = (strtoupper(substr($ans, 0, 1)) === strtoupper($q['correct_answer']));
            $earned = $correct ? $q['points'] : 0;
        } elseif ($q['qtype'] === 'truefalse') {
            $correct = (strtolower($ans) === strtolower($q['correct_answer']));
            $earned = $correct ? $q['points'] : 0;
        } else { // identification
            $correct = (strtolower($ans) === strtolower(trim($q['correct_answer'])));
            $earned = $correct ? $q['points'] : 0;
        }
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
  <p class="hint">One submission only. Timer auto-submits when time runs out.<?= $shuffle ? ' Questions are shuffled.' : '' ?><?= $allow_retake ? ' Retake allowed.' : '' ?></p>
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