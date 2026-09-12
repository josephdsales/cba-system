<?php
// Shared section manager used by BOTH admin and teacher.
// Visibility: admin sees all. A teacher sees only:
//   - sections they created, plus
//   - sections admin assigned to them, plus shared (unassigned) ones.
// Teachers can edit/delete ONLY sections they created;
// admin-assigned ones are read-only for the teacher.
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';

$me = require_login();
if (!in_array($me['role'], ['admin', 'teacher'], true)) { http_response_code(403); exit('Forbidden'); }
$is_admin_page = ($me['role'] === 'admin');
$user = $me;

$teachers = db()->query("SELECT id, fullname FROM users WHERE role='teacher' ORDER BY (lastname IS NULL), lastname, firstname, fullname")->fetchAll();

function can_edit_section(array $s, array $me): bool {
    // Admin sees every section; a teacher's list is already filtered to
    // visible sections (own + assigned + shared), all editable.
    return true;
}

$edit = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT s.*, t.fullname AS teacher_name FROM sections s LEFT JOIN users t ON t.id=s.assigned_teacher_id WHERE s.id=?');
    $st->execute([(int)$_GET['edit']]); $edit = $st->fetch();
    if ($edit && !can_edit_section($edit, $me)) { http_response_code(403); exit('Forbidden: only the teacher who created this section (or admin) can edit it.'); }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $back = $is_admin_page ? 'admin_sections.php' : 'teacher_sections.php';
    if (($_POST['action'] ?? '') === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        // Admin picks assignee; teacher's sections auto-belong to themselves.
        $assigned = $is_admin_page ? (int)($_POST['assigned_teacher_id'] ?? 0) : (int)$me['id'];
        if ($name === '') set_flash('Section name is required.');
        else {
            try {
                if ($id > 0) {
                    $st = db()->prepare('SELECT * FROM sections WHERE id=?');
                    $st->execute([$id]); $cur = $st->fetch();
                    if (!$cur || !can_edit_section($cur, $me)) { set_flash('Not allowed to edit this section.'); }
                    else {
                        if ($is_admin_page) {
                            $st = db()->prepare('UPDATE sections SET name=?, description=?, assigned_teacher_id=? WHERE id=?');
                            $st->execute([$name, $desc ?: null, $assigned ?: null, $id]);
                        } else {
                            $st = db()->prepare('UPDATE sections SET name=?, description=? WHERE id=?');
                            $st->execute([$name, $desc ?: null, $id]);
                        }
                        set_flash('Section updated.');
                    }
                } else {
                    $st = db()->prepare('INSERT INTO sections (name, description, created_by, assigned_teacher_id) VALUES (?, ?, ?, ?)');
                    $st->execute([$name, $desc ?: null, $me['id'], $assigned ?: null]);
                    set_flash($is_admin_page ? 'Section added.' : 'Section added (visible only to you).');
                }
            } catch (PDOException $ex) { set_flash('Error: section name already exists.'); }
        }
        header("Location: $back"); exit;
    }
    if (($_POST['action'] ?? '') === 'delete') {
        $id = (int)$_POST['id'];
        $st = db()->prepare('SELECT * FROM sections WHERE id=?');
        $st->execute([$id]); $cur = $st->fetch();
        if (!$cur || !can_edit_section($cur, $me)) set_flash('Not allowed to delete this section.');
        else {
            try {
                $st = db()->prepare('DELETE FROM sections WHERE id=?');
                $st->execute([$id]);
                set_flash('Section deleted.');
            } catch (PDOException $ex) { set_flash('Cannot delete: section is in use by students/exams.'); }
        }
        header("Location: $back"); exit;
    }
}

if ($is_admin_page) {
    $sections = db()->query("SELECT s.*, t.fullname AS teacher_name, (SELECT COUNT(*) FROM users u WHERE u.section_id=s.id) AS student_count FROM sections s LEFT JOIN users t ON t.id=s.assigned_teacher_id ORDER BY s.name")->fetchAll();
} else {
    $sections = visible_sections((int)$me['id']);
}
$title = 'Manage Sections';
include __DIR__ . '/includes/header.php';
$this_page = $is_admin_page ? 'admin_sections.php' : 'teacher_sections.php';
?>
<div class="card">
  <h3 style="margin-top:0"><?= $edit ? 'Edit section' : 'Add section' ?></h3>
  <?php if (!$is_admin_page): ?><p class="hint">Sections you create are visible <b>only to you</b> — other teachers won't see them.</p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="grid two">
      <div><label>Section name</label><input type="text" name="name" required value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. BSIT-1A"></div>
      <div><label>Description (optional)</label><input type="text" name="description" value="<?= e($edit['description'] ?? '') ?>"></div>
    </div>
    <?php if ($is_admin_page): ?>
      <label>Assign to teacher</label>
      <select name="assigned_teacher_id">
        <option value="0">— Shared (all teachers) —</option>
        <?php foreach ($teachers as $t): ?>
          <option value="<?= $t['id'] ?>" <?= ((int)($edit['assigned_teacher_id'] ?? 0) === (int)$t['id']) ? 'selected' : '' ?>>Only: <?= e($t['fullname']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <div class="btnrow"><button class="btn" type="submit"><?= $edit ? 'Save' : 'Add section' ?></button>
    <?php if ($edit): ?><a class="btn ghost" href="<?= $this_page ?>">Cancel</a><?php endif; ?></div>
  </form>
</div>
<div class="card"><div class="table-wrap"><table>
  <tr><th>Section</th><th>Description</th><th><?= $is_admin_page ? 'Assigned to' : 'Owner' ?></th><th>Students</th><th>Actions</th></tr>
  <?php foreach ($sections as $s): ?>
  <tr>
    <td><b><?= e($s['name']) ?></b></td><td><?= e($s['description'] ?? '') ?></td>
    <td><?php if ($is_admin_page): ?><?= e($s['teacher_name'] ?? 'Shared (all)') ?>
      <?php else: ?><?= ((int)($s['created_by'] ?? 0) === (int)$me['id']) ? 'Mine' : ('Assigned by admin' . ($s['assigned_teacher_id'] ? '' : ' · Shared')) ?><?php endif; ?></td>
    <td><?= $s['student_count'] ?></td>
    <td><?php if (can_edit_section($s, $me)): ?><div class="btnrow" style="margin:0">
      <a class="btn small ghost" href="<?= $this_page ?>?edit=<?= $s['id'] ?>">Edit</a>
      <form method="post" style="display:inline" onsubmit="return confirm('Delete section <?= e($s['name']) ?>?')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>">
        <button class="btn small danger" type="submit">Delete</button>
      </form>
    </div><?php else: ?><small class="hint">read-only</small><?php endif; ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$sections): ?><tr><td colspan="5" class="hint">No sections visible to you yet.</td></tr><?php endif; ?>
</table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
