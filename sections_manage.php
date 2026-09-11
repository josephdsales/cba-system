<?php
// Shared section manager used by BOTH admin and teacher.
// Only difference: who is allowed in.
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';

$me = require_login();
if (!in_array($me['role'], ['admin', 'teacher'], true)) { http_response_code(403); exit('Forbidden'); }
$is_admin_page = ($me['role'] === 'admin');
$user = $me;

$edit = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM sections WHERE id=?');
    $st->execute([(int)$_GET['edit']]); $edit = $st->fetch();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $back = $is_admin_page ? 'admin_sections.php' : 'teacher_sections.php';
    if (($_POST['action'] ?? '') === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name === '') set_flash('Section name is required.');
        else {
            try {
                if ($id > 0) {
                    $st = db()->prepare('UPDATE sections SET name=?, description=? WHERE id=?');
                    $st->execute([$name, $desc ?: null, $id]);
                    set_flash('Section updated.');
                } else {
                    $st = db()->prepare('INSERT INTO sections (name, description, created_by) VALUES (?, ?, ?)');
                    $st->execute([$name, $desc ?: null, $me['id']]);
                    set_flash('Section added.');
                }
            } catch (PDOException $ex) { set_flash('Error: section name already exists.'); }
        }
        header("Location: $back"); exit;
    }
    if (($_POST['action'] ?? '') === 'delete') {
        try {
            $st = db()->prepare('DELETE FROM sections WHERE id=?');
            $st->execute([(int)$_POST['id']]);
            set_flash('Section deleted.');
        } catch (PDOException $ex) { set_flash('Cannot delete: section is in use by students/exams.'); }
        header("Location: $back"); exit;
    }
}

$sections = db()->query('SELECT s.*, (SELECT COUNT(*) FROM users u WHERE u.section_id=s.id) AS student_count FROM sections s ORDER BY s.name')->fetchAll();
$title = 'Manage Sections';
include __DIR__ . '/includes/header.php';
$this_page = $is_admin_page ? 'admin_sections.php' : 'teacher_sections.php';
?>
<div class="card">
  <h3 style="margin-top:0"><?= $edit ? 'Edit section' : 'Add section' ?></h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="grid two">
      <div><label>Section name</label><input type="text" name="name" required value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. BSIT-1A"></div>
      <div><label>Description (optional)</label><input type="text" name="description" value="<?= e($edit['description'] ?? '') ?>"></div>
    </div>
    <div class="btnrow"><button class="btn" type="submit"><?= $edit ? 'Save' : 'Add section' ?></button>
    <?php if ($edit): ?><a class="btn ghost" href="<?= $this_page ?>">Cancel</a><?php endif; ?></div>
  </form>
</div>
<div class="card"><div class="table-wrap"><table>
  <tr><th>Section</th><th>Description</th><th>Students</th><th>Actions</th></tr>
  <?php foreach ($sections as $s): ?>
  <tr>
    <td><b><?= e($s['name']) ?></b></td><td><?= e($s['description'] ?? '') ?></td><td><?= $s['student_count'] ?></td>
    <td><div class="btnrow" style="margin:0">
      <a class="btn small ghost" href="<?= $this_page ?>?edit=<?= $s['id'] ?>">Edit</a>
      <form method="post" style="display:inline" onsubmit="return confirm('Delete section <?= e($s['name']) ?>?')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>">
        <button class="btn small danger" type="submit">Delete</button>
      </form>
    </div></td>
  </tr>
  <?php endforeach; ?>
</table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
