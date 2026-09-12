<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'reset') {
        $new = $_POST['new_password'] ?? '';
        if (strlen($new) < 6) set_flash('Reset password must be min 6 chars.');
        else {
            $st = db()->prepare("UPDATE users SET password_hash=? WHERE id=? AND role='student'");
            $st->execute([password_hash($new, PASSWORD_DEFAULT), (int)$_POST['id']]);
            set_flash('Student password has been reset.');
        }
    } elseif ($action === 'delete') {
        $st = db()->prepare("DELETE FROM users WHERE id=? AND role='student'");
        $st->execute([(int)$_POST['id']]);
        set_flash('Student deleted.');
    }
    header('Location: admin_students.php'); exit;
}

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $st = db()->prepare("SELECT u.*, s.name AS section_name FROM users u LEFT JOIN sections s ON s.id=u.section_id
                         WHERE u.role='student' AND (u.fullname LIKE ? OR u.username LIKE ?) ORDER BY (u.lastname IS NULL), u.lastname, u.firstname, u.fullname");
    $st->execute(["%$q%", "%$q%"]);
} else {
    $st = db()->query("SELECT u.*, s.name AS section_name FROM users u LEFT JOIN sections s ON s.id=u.section_id WHERE u.role='student' ORDER BY (u.lastname IS NULL), u.lastname, u.firstname, u.fullname");
}
$students = $st->fetchAll();
$title = 'Manage Students';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap">
    <input type="text" name="q" placeholder="Search name or username..." value="<?= e($q) ?>" style="flex:1;min-width:200px">
    <button class="btn" type="submit">Search</button>
  </form>
  <p class="hint">Total: <?= count($students) ?> student(s). Admin can reset any student password or delete accounts.</p>
</div>
<div class="card"><div class="table-wrap"><table>
  <tr><th>Fullname</th><th>Gender</th><th>Section</th><th>Username</th><th>Actions</th></tr>
  <?php foreach ($students as $s): ?>
  <tr>
    <td><?= e($s['fullname']) ?></td><td><?= e($s['gender']) ?></td>
    <td><?= e($s['section_name'] ?? '—') ?></td><td><?= e($s['username']) ?></td>
    <td>
      <div class="btnrow" style="margin:0">
        <form method="post" style="display:inline" onsubmit="return confirm('Reset password for <?= e($s['username']) ?>?')">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= $s['id'] ?>">
          <input type="text" name="new_password" placeholder="new pass" required style="width:110px;display:inline-block" minlength="6">
          <button class="btn small ok" type="submit">Reset PW</button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this student and all their attempts?')">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>">
          <button class="btn small danger" type="submit">Delete</button>
        </form>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$students): ?><tr><td colspan="5" class="hint">No students found.</td></tr><?php endif; ?>
</table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
