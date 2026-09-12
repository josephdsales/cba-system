<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('admin');

$edit = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare("SELECT * FROM users WHERE id=? AND role='teacher'");
    $st->execute([(int)$_GET['edit']]); $edit = $st->fetch();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $last = trim($_POST['lastname'] ?? '');
        $first = trim($_POST['firstname'] ?? '');
        $mi_raw = trim($_POST['mi'] ?? '');
        $mi = $mi_raw !== '' ? rtrim($mi_raw, '.') . '.' : null;
        $fullname = $last . ', ' . $first . ($mi ? ' ' . $mi : '');
        $gender = $_POST['gender'] ?? 'Other';
        if (!in_array($gender, ['Male', 'Female', 'Other'], true)) $gender = 'Other';
        $username = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';
        if (strlen($last) < 2 || strlen($first) < 2 || strlen($username) < 3) set_flash('Last name, first name (min 2 chars) and username (min 3 chars) required.');
        else {
            try {
                if ($id > 0) {
                    if ($pass !== '') {
                        $st = db()->prepare('UPDATE users SET fullname=?, lastname=?, firstname=?, mi=?, gender=?, username=?, password_hash=? WHERE id=? AND role="teacher"');
                        $st->execute([$fullname, $last, $first, $mi, $gender, $username, password_hash($pass, PASSWORD_DEFAULT), $id]);
                    } else {
                        $st = db()->prepare('UPDATE users SET fullname=?, lastname=?, firstname=?, mi=?, gender=?, username=? WHERE id=? AND role="teacher"');
                        $st->execute([$fullname, $last, $first, $mi, $gender, $username, $id]);
                    }
                    set_flash('Teacher updated.');
                } else {
                    if (strlen($pass) < 6) set_flash('New teacher needs a password (min 6 chars).');
                    else {
                        $st = db()->prepare("INSERT INTO users (fullname, lastname, firstname, mi, gender, username, password_hash, role) VALUES (?, ?, ?, ?, ?, ?, ?, 'teacher')");
                        $st->execute([$fullname, $last, $first, $mi, $gender, $username, password_hash($pass, PASSWORD_DEFAULT)]);
                        set_flash('Teacher added.');
                    }
                }
            } catch (PDOException $ex) { set_flash('Error: username already exists.'); }
        }
        header('Location: admin_teachers.php'); exit;
    }
    if ($action === 'delete') {
        $st = db()->prepare("DELETE FROM users WHERE id=? AND role='teacher'");
        $st->execute([(int)$_POST['id']]);
        set_flash('Teacher deleted.');
        header('Location: admin_teachers.php'); exit;
    }
    if ($action === 'reset') {
        $new = $_POST['new_password'] ?? '';
        if (strlen($new) < 6) set_flash('Reset password must be min 6 chars.');
        else {
            $st = db()->prepare("UPDATE users SET password_hash=? WHERE id=? AND role='teacher'");
            $st->execute([password_hash($new, PASSWORD_DEFAULT), (int)$_POST['id']]);
            set_flash('Teacher password has been reset.');
        }
        header('Location: admin_teachers.php'); exit;
    }
}

$teachers = db()->query("SELECT * FROM users WHERE role='teacher' ORDER BY (lastname IS NULL), lastname, firstname, fullname")->fetchAll();
$title = 'Manage Teachers';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <h3 style="margin-top:0"><?= $edit ? 'Edit teacher' : 'Add teacher' ?></h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="grid two">
      <div><label>Last name</label><input type="text" name="lastname" required value="<?= e($edit['lastname'] ?? '') ?>"></div>
      <div><label>First name</label><input type="text" name="firstname" required value="<?= e($edit['firstname'] ?? '') ?>"></div>
      <div><label>Middle initial (optional)</label><input type="text" name="mi" maxlength="3" value="<?= e($edit['mi'] ?? '') ?>"></div>
      <div><label>Gender</label><select name="gender">
        <?php foreach (['Male','Female','Other'] as $g): ?><option <?= (($edit['gender'] ?? 'Other') === $g) ? 'selected' : '' ?>><?= $g ?></option><?php endforeach; ?>
      </select></div>
      <div><label>Username</label><input type="text" name="username" required value="<?= e($edit['username'] ?? '') ?>"></div>
      <div><label>Password <?= $edit ? '(leave blank to keep)' : '' ?></label><input type="password" name="password" <?= $edit ? '' : 'required' ?>></div>
    </div>
    <div class="btnrow"><button class="btn" type="submit"><?= $edit ? 'Save changes' : 'Add teacher' ?></button>
    <?php if ($edit): ?><a class="btn ghost" href="admin_teachers.php">Cancel</a><?php endif; ?></div>
  </form>
</div>
<div class="card"><div class="table-wrap"><table>
  <tr><th>Fullname</th><th>Gender</th><th>Username</th><th>Created</th><th>Actions</th></tr>
  <?php foreach ($teachers as $t): ?>
  <tr>
    <td><?= e($t['fullname']) ?></td><td><?= e($t['gender']) ?></td><td><?= e($t['username']) ?></td><td><?= e($t['created_at']) ?></td>
    <td>
      <div class="btnrow" style="margin:0">
        <a class="btn small ghost" href="admin_teachers.php?edit=<?= $t['id'] ?>">Edit</a>
        <form method="post" style="display:inline" onsubmit="return confirm('Reset password for <?= e($t['username']) ?>?')">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= $t['id'] ?>">
          <input type="text" name="new_password" placeholder="new pass" required style="width:110px;display:inline-block" minlength="6">
          <button class="btn small ok" type="submit">Reset</button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this teacher?')">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $t['id'] ?>">
          <button class="btn small danger" type="submit">Delete</button>
        </form>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$teachers): ?><tr><td colspan="5" class="hint">No teachers yet. Add one above.</td></tr><?php endif; ?>
</table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
