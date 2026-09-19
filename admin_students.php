<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $last = trim($_POST['lastname'] ?? '');
        $first = trim($_POST['firstname'] ?? '');
        $mi_raw = trim($_POST['mi'] ?? '');
        $mi = $mi_raw !== '' ? rtrim($mi_raw, '.') . '.' : null;
        $gender = $_POST['gender'] ?? 'Other';
        if (!in_array($gender, ['Male', 'Female', 'Other'], true)) $gender = 'Other';
        $section = (int)($_POST['section_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        if (strlen($last) < 2 || strlen($first) < 2 || strlen($username) < 3) {
            set_flash('Last name, first name (min 2 chars) and username (min 3 chars) are required.');
        } else {
            try {
                $fullname = $last . ', ' . $first . ($mi ? ' ' . $mi : '');
                $st = db()->prepare("UPDATE users SET fullname=?, lastname=?, firstname=?, mi=?, gender=?, section_id=?, username=? WHERE id=? AND role='student'");
                $st->execute([$fullname, $last, $first, $mi, $gender, $section ?: null, $username, $id]);
                set_flash('Student details updated.');
            } catch (PDOException $ex) { set_flash('Save failed: ' . $ex->getMessage()); }
        }
        header('Location: admin_students.php'); exit;
    }
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
$sort = $_GET['sort'] ?? 'name';
if (!in_array($sort, ['name', 'gender', 'section', 'username', 'created'], true)) $sort = 'name';
$sdir = ($_GET['dir'] ?? 'asc') === 'asc' ? 'asc' : 'desc';

$order_map = [
    'name' => '(u.lastname IS NULL), u.lastname, u.firstname, u.fullname',
    'gender' => 'u.gender',
    'section' => 's.name',
    'username' => 'u.username',
    'created' => 'u.created_at'
];
$order_sql = $order_map[$sort] . ' ' . strtoupper($sdir);

if ($q !== '') {
    $st = db()->prepare("SELECT u.*, s.name AS section_name FROM users u LEFT JOIN sections s ON s.id=u.section_id
                         WHERE u.role='student' AND (u.fullname LIKE ? OR u.username LIKE ?) ORDER BY $order_sql");
    $st->execute(["%$q%", "%$q%"]);
} else {
    $st = db()->query("SELECT u.*, s.name AS section_name FROM users u LEFT JOIN sections s ON s.id=u.section_id WHERE u.role='student' ORDER BY $order_sql");
}
$students = $st->fetchAll();
$sections = db()->query('SELECT * FROM sections ORDER BY name')->fetchAll();
$edit = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare("SELECT * FROM users WHERE id=? AND role='student'");
    $st->execute([(int)$_GET['edit']]); $edit = $st->fetch();
}
$title = 'Manage Students';
include __DIR__ . '/includes/header.php';

function sort_link($label, $key) {
    global $q, $sort, $sdir;
    $nd = ($sort === $key && $sdir === 'desc') ? 'asc' : 'desc';
    $arrow = $sort === $key ? ($sdir === 'desc' ? ' ▼' : ' ▲') : '';
    $qs = $q !== '' ? '&q=' . urlencode($q) : '';
    return '<a href="admin_students.php?sort=' . $key . '&dir=' . $nd . $qs . '">' . e($label) . $arrow . '</a>';
}
?>
<?php if ($edit): ?>
<div class="card">
  <h3 style="margin-top:0">Edit student</h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $edit['id'] ?>">
    <div class="grid two">
      <div><label>Last name</label><input type="text" name="lastname" required value="<?= e($edit['lastname'] ?? '') ?>"></div>
      <div><label>First name</label><input type="text" name="firstname" required value="<?= e($edit['firstname'] ?? '') ?>"></div>
    </div>
    <label>Middle initial (optional)</label>
    <input type="text" name="mi" maxlength="3" value="<?= e($edit['mi'] ?? '') ?>">
    <div class="grid two">
      <div><label>Gender</label><select name="gender">
        <?php foreach (['Male', 'Female', 'Other'] as $g): ?><option <?= (($edit['gender'] ?? '') === $g) ? 'selected' : '' ?>><?= $g ?></option><?php endforeach; ?>
      </select></div>
      <div><label>Section</label><select name="section_id">
        <option value="0">— none —</option>
        <?php foreach ($sections as $sec): ?><option value="<?= $sec['id'] ?>" <?= ((int)($edit['section_id'] ?? 0) === (int)$sec['id']) ? 'selected' : '' ?>><?= e($sec['name']) ?></option><?php endforeach; ?>
      </select></div>
    </div>
    <label>Username</label>
    <input type="text" name="username" required value="<?= e($edit['username']) ?>">
    <p class="hint">Password: use Reset PW below to change it.</p>
    <div class="btnrow"><button class="btn" type="submit">Save changes</button>
    <a class="btn ghost" href="admin_students.php">Cancel</a></div>
  </form>
</div>
<?php endif; ?>
<div class="card">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap">
    <input type="text" name="q" placeholder="Search name or username..." value="<?= e($q) ?>" style="flex:1;min-width:200px">
    <button class="btn" type="submit">Search</button>
  </form>
  <p class="hint">Total: <?= count($students) ?> student(s). Admin can reset any student password or delete accounts.</p>
</div>
<div class="card"><div class="table-wrap"><table>
  <tr>
    <th><?= sort_link('Fullname', 'name') ?></th>
    <th><?= sort_link('Gender', 'gender') ?></th>
    <th><?= sort_link('Section', 'section') ?></th>
    <th><?= sort_link('Username', 'username') ?></th>
    <th>Actions</th>
  </tr>
  <?php foreach ($students as $s): ?>
  <tr>
    <td><?= e($s['fullname']) ?></td><td><?= e($s['gender']) ?></td>
    <td><?= e($s['section_name'] ?? '—') ?></td><td><?= e($s['username']) ?></td>
    <td>
      <div class="btnrow" style="margin:0">
        <a class="btn small ghost" href="admin_students.php?edit=<?= $s['id'] ?>">Edit</a>
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
