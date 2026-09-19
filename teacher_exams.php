<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('teacher');

$sections = visible_sections((int)$user['id']);
$show_new = isset($_GET['action']) && $_GET['action'] === 'new';
$edit = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM exams WHERE id=? AND teacher_id=?');
    $st->execute([(int)$_GET['edit'], $user['id']]); $edit = $st->fetch();
    $show_new = (bool)$edit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $sec = (int)($_POST['section_id'] ?? 0);
        $limit = max(1, (int)($_POST['time_limit_minutes'] ?? 60));
        $pass = min(100, max(0, (float)($_POST['passing_percent'] ?? 50)));
        $status = in_array($_POST['status'] ?? '', ['draft','published','closed'], true) ? $_POST['status'] : 'draft';
        $shuffle = !empty($_POST['shuffle_questions']) ? 1 : 0;
        $retake = !empty($_POST['allow_retake']) ? 1 : 0;
        if ($title === '') set_flash('Exam title is required.');
        else {
            if ($id > 0) {
                $st = db()->prepare('UPDATE exams SET title=?, description=?, section_id=?, time_limit_minutes=?, passing_percent=?, status=?, shuffle_questions=?, allow_retake=? WHERE id=? AND teacher_id=?');
                $st->execute([$title, $desc ?: null, $sec ?: null, $limit, $pass, $status, $shuffle, $retake, $id, $user['id']]);
                set_flash('Exam updated.');
            } else {
                $st = db()->prepare('INSERT INTO exams (title, description, teacher_id, section_id, time_limit_minutes, passing_percent, status, shuffle_questions, allow_retake) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $st->execute([$title, $desc ?: null, $user['id'], $sec ?: null, $limit, $pass, $status, $shuffle, $retake]);
                $id = (int)db()->lastInsertId();
                set_flash('Exam created. Now add questions.');
                header("Location: teacher_questions.php?exam_id=$id"); exit;
            }
        }
        header('Location: teacher_exams.php'); exit;
    }
    if ($action === 'delete') {
        $st = db()->prepare('DELETE FROM exams WHERE id=? AND teacher_id=?');
        $st->execute([(int)$_POST['id'], $user['id']]);
        set_flash('Exam deleted.');
        header('Location: teacher_exams.php'); exit;
    }
}

$st = db()->prepare("SELECT e.*, s.name AS section_name, (SELECT COUNT(*) FROM questions q WHERE q.exam_id=e.id) AS qcount,
    (SELECT COUNT(*) FROM attempts a WHERE a.exam_id=e.id AND a.submitted_at IS NOT NULL) AS takers
    FROM exams e LEFT JOIN sections s ON s.id=e.section_id WHERE e.teacher_id=? ORDER BY e.created_at DESC");
$st->execute([$user['id']]);
$exams = $st->fetchAll();
$title = 'My Exams';
include __DIR__ . '/includes/header.php';
?>
<?php if ($show_new || $edit): ?>
<div class="card">
  <h3 style="margin-top:0"><?= $edit ? 'Edit exam' : 'Create exam' ?></h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <label>Title</label><input type="text" name="title" required value="<?= e($edit['title'] ?? '') ?>">
    <label>Description</label><textarea name="description"><?= e($edit['description'] ?? '') ?></textarea>
    <div class="grid two">
      <div><label>Section (blank = all sections)</label><select name="section_id">
        <option value="0">All sections</option>
        <?php foreach ($sections as $s): ?><option value="<?= $s['id'] ?>" <?= (($edit['section_id'] ?? 0) == $s['id']) ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
      </select></div>
      <div><label>Time limit (minutes)</label><input type="number" name="time_limit_minutes" min="1" value="<?= e($edit['time_limit_minutes'] ?? 60) ?>"></div>
      <div><label>Passing %</label><input type="number" name="passing_percent" min="0" max="100" step="0.01" value="<?= e($edit['passing_percent'] ?? 50) ?>"></div>
      <div><label>Status</label><select name="status">
        <?php foreach (['draft'=>'Draft (hidden)','published'=>'Published (students can take)','closed'=>'Closed'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= (($edit['status'] ?? 'draft') === $k) ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select></div>
      <div><label><input type="checkbox" name="shuffle_questions" value="1" <?= !empty($edit['shuffle_questions']) ? 'checked' : '' ?>> Shuffle question order</label></div>
      <div><label><input type="checkbox" name="allow_retake" value="1" <?= !empty($edit['allow_retake']) ? 'checked' : '' ?>> Allow retake</label></div>
    </div>
    <div class="btnrow"><button class="btn" type="submit">Save exam</button><a class="btn ghost" href="teacher_exams.php">Cancel</a></div>
  </form>
</div>
<?php else: ?>
<div class="card"><a class="btn" href="teacher_exams.php?action=new">+ Create Exam</a></div>
<?php endif; ?>

<div class="card"><div class="table-wrap"><table>
  <tr><th>Exam</th><th>Section</th><th>Qs</th><th>Takers</th><th>Status</th><th>Actions</th></tr>
  <?php foreach ($exams as $x): ?>
  <tr>
    <td><b><?= e($x['title']) ?></b><br><small class="hint"><?= e(mb_strimwidth($x['description'] ?? '', 0, 80, '…')) ?> · <?= $x['time_limit_minutes'] ?> min</small></td>
    <td><?= e($x['section_name'] ?? 'All') ?></td><td><?= $x['qcount'] ?></td><td><?= $x['takers'] ?></td>
    <td><span class="badge b-<?= e($x['status']) ?>"><?= e($x['status']) ?></span></td>
    <td><div class="btnrow" style="margin:0">
      <a class="btn small" href="teacher_questions.php?exam_id=<?= $x['id'] ?>">Questions</a>
      <a class="btn small ghost" href="teacher_import.php?exam_id=<?= $x['id'] ?>">Import Word</a>
      <a class="btn small ghost" href="teacher_exams.php?edit=<?= $x['id'] ?>">Edit</a>
      <a class="btn small ghost" href="teacher_results.php?exam_id=<?= $x['id'] ?>">Results</a>
      <form method="post" style="display:inline" onsubmit="return confirm('Delete this exam and all its questions/results?')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $x['id'] ?>">
        <button class="btn small danger" type="submit">Delete</button>
      </form>
    </div></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$exams): ?><tr><td colspan="6" class="hint">No exams yet. Click Create Exam.</td></tr><?php endif; ?>
</table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
