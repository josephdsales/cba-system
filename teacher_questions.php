<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('teacher');

$exam_id = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
$st = db()->prepare('SELECT * FROM exams WHERE id=? AND teacher_id=?');
$st->execute([$exam_id, $user['id']]);
$exam = $st->fetch();
if (!$exam) { header('Location: teacher_exams.php'); exit; }

$edit = null;
if (isset($_GET['qedit'])) {
    $st = db()->prepare('SELECT * FROM questions WHERE id=? AND exam_id=?');
    $st->execute([(int)$_GET['qedit'], $exam_id]); $edit = $st->fetch();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $qid = (int)($_POST['qid'] ?? 0);
        $text = trim($_POST['question_text'] ?? '');
        $qtype = $_POST['qtype'] ?? 'mcq';
        if (!in_array($qtype, ['mcq','truefalse','identification'], true)) $qtype = 'mcq';
        $oa = trim($_POST['option_a'] ?? ''); $ob = trim($_POST['option_b'] ?? '');
        $oc = trim($_POST['option_c'] ?? ''); $od = trim($_POST['option_d'] ?? '');
        $correct = trim($_POST['correct_answer'] ?? '');
        $points = max(1, (int)($_POST['points'] ?? 1));
        if ($text === '' || $correct === '') set_flash('Question text and correct answer are required.');
        else {
            if ($qtype === 'mcq') $correct = strtoupper(substr($correct, 0, 1));
            if ($qid > 0) {
                $st = db()->prepare('UPDATE questions SET question_text=?, qtype=?, option_a=?, option_b=?, option_c=?, option_d=?, correct_answer=?, points=? WHERE id=? AND exam_id=?');
                $st->execute([$text, $qtype, $oa ?: null, $ob ?: null, $oc ?: null, $od ?: null, $correct, $points, $qid, $exam_id]);
                set_flash('Question updated.');
            } else {
                $st = db()->prepare('SELECT COALESCE(MAX(sort_order),0)+1 n FROM questions WHERE exam_id=?');
                $st->execute([$exam_id]); $n = $st->fetch()['n'];
                $st = db()->prepare('INSERT INTO questions (exam_id, question_text, qtype, option_a, option_b, option_c, option_d, correct_answer, points, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $st->execute([$exam_id, $text, $qtype, $oa ?: null, $ob ?: null, $oc ?: null, $od ?: null, $correct, $points, $n]);
                set_flash('Question added.');
            }
        }
        header("Location: teacher_questions.php?exam_id=$exam_id"); exit;
    }
    if ($action === 'delete') {
        $st = db()->prepare('DELETE FROM questions WHERE id=? AND exam_id=?');
        $st->execute([(int)$_POST['qid'], $exam_id]);
        set_flash('Question deleted.');
        header("Location: teacher_questions.php?exam_id=$exam_id"); exit;
    }
}

$st = db()->prepare('SELECT * FROM questions WHERE exam_id=? ORDER BY sort_order, id');
$st->execute([$exam_id]); $questions = $st->fetchAll();
$title = 'Questions: ' . $exam['title'];
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <p class="hint">Exam: <b><?= e($exam['title']) ?></b> · <?= count($questions) ?> question(s) ·
  <a href="teacher_import.php?exam_id=<?= $exam_id ?>">Import from Word (.docx)</a> ·
  <a href="teacher_exams.php">Back to exams</a></p>
</div>
<div class="card">
  <h3 style="margin-top:0"><?= $edit ? 'Edit question #' . $edit['id'] : 'Add question' ?></h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="exam_id" value="<?= $exam_id ?>">
    <input type="hidden" name="qid" value="<?= (int)($edit['id'] ?? 0) ?>">
    <label>Question</label><textarea name="question_text" required><?= e($edit['question_text'] ?? '') ?></textarea>
    <div class="grid two">
      <div><label>Type</label><select name="qtype" id="qtype">
        <?php foreach (['mcq'=>'Multiple choice (A–D)','truefalse'=>'True / False','identification'=>'Identification (typed answer)'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= (($edit['qtype'] ?? 'mcq') === $k) ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select></div>
      <div><label>Points</label><input type="number" name="points" min="1" value="<?= e($edit['points'] ?? 1) ?>"></div>
    </div>
    <div id="mcq-opts">
      <div class="grid two">
        <div><label>Option A</label><input type="text" name="option_a" value="<?= e($edit['option_a'] ?? '') ?>"></div>
        <div><label>Option B</label><input type="text" name="option_b" value="<?= e($edit['option_b'] ?? '') ?>"></div>
        <div><label>Option C</label><input type="text" name="option_c" value="<?= e($edit['option_c'] ?? '') ?>"></div>
        <div><label>Option D</label><input type="text" name="option_d" value="<?= e($edit['option_d'] ?? '') ?>"></div>
      </div>
    </div>
    <label>Correct answer <small class="hint">(mcq: A/B/C/D · truefalse: True/False · identification: exact text)</small></label>
    <input type="text" name="correct_answer" required value="<?= e($edit['correct_answer'] ?? '') ?>">
    <div class="btnrow"><button class="btn" type="submit"><?= $edit ? 'Save' : 'Add' ?></button>
    <?php if ($edit): ?><a class="btn ghost" href="teacher_questions.php?exam_id=<?= $exam_id ?>">Cancel</a><?php endif; ?></div>
  </form>
</div>
<?php $i = 1; foreach ($questions as $q): ?>
<div class="q">
  <h3><?= $i++ ?>. <?= e($q['question_text']) ?> <small class="hint">[<?= e($q['qtype']) ?> · <?= $q['points'] ?> pt(s)]</small></h3>
  <?php if ($q['qtype'] === 'mcq'): ?>
    <div class="hint">A. <?= e($q['option_a']) ?> · B. <?= e($q['option_b']) ?> · C. <?= e($q['option_c']) ?> · D. <?= e($q['option_d']) ?> → <b>Answer: <?= e($q['correct_answer']) ?></b></div>
  <?php else: ?>
    <div class="hint">Answer: <b><?= e($q['correct_answer']) ?></b></div>
  <?php endif; ?>
  <div class="btnrow">
    <a class="btn small ghost" href="teacher_questions.php?exam_id=<?= $exam_id ?>&qedit=<?= $q['id'] ?>">Edit</a>
    <form method="post" style="display:inline" onsubmit="return confirm('Delete this question?')">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="exam_id" value="<?= $exam_id ?>">
      <input type="hidden" name="qid" value="<?= $q['id'] ?>">
      <button class="btn small danger" type="submit">Delete</button>
    </form>
  </div>
</div>
<?php endforeach; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
