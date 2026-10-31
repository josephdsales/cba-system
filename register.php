<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';

if (current_user()) { header('Location: dashboard.php'); exit; }
$error = '';
try { $sections = db()->query('SELECT * FROM sections ORDER BY name')->fetchAll(); }
catch (Throwable $ex) { $sections = []; $error = 'Database not ready. Ask admin to run install.php first.'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    // Names are stored UPPERCASE regardless of how they were typed.
    $last = to_upper_name(trim($_POST['lastname'] ?? ''));
    $first = to_upper_name(trim($_POST['firstname'] ?? ''));
    $mi = to_upper_name(trim($_POST['mi'] ?? ''));
    $mi = $mi !== '' ? rtrim($mi, '.') . '.' : '';
    $fullname = $last . ', ' . $first . ($mi !== '' ? ' ' . $mi : '');
    $gender   = $_POST['gender'] ?? '';
    $section  = (int)($_POST['section_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $p1 = $_POST['password'] ?? ''; $p2 = $_POST['password2'] ?? '';
    if (strlen($last) < 2 || strlen($first) < 2) $error = 'Please enter your last name and first name.';
    elseif (!in_array($gender, ['Male','Female','Other'], true)) $error = 'Please select gender.';
    elseif ($section <= 0) $error = 'Please select your section.';
    elseif (strlen($username) < 3) $error = 'Username must be at least 3 characters.';
    elseif (strlen($p1) < 6) $error = 'Password must be at least 6 characters.';
    elseif ($p1 !== $p2) $error = 'Passwords do not match.';
    elseif (username_taken($username)) $error = 'Username already taken. Choose another.';
    else {
        try {
            $st = db()->prepare("INSERT INTO users (fullname, lastname, firstname, mi, gender, section_id, username, password_hash, role)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'student')");
            $st->execute([$fullname, $last, $first, ($mi !== '' ? $mi : null), $gender, $section, $username, password_hash($p1, PASSWORD_DEFAULT)]);
            set_flash('Account created. You can now log in.');
            header('Location: index.php'); exit;
        } catch (PDOException $ex) {
            $error = stripos($ex->getMessage(), 'duplicate') !== false ? 'Username already taken. Choose another.' : 'Registration failed: ' . $ex->getMessage();
        }
    }
}

$title = 'Student Registration'; $user = null;
include __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:520px;margin:20px auto;">
  <?php if ($error): ?><p style="color:#b91c1c"><b><?= e($error) ?></b></p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="grid two">
      <div><label>Last name</label>
      <input type="text" name="lastname" required value="<?= e($last ?? ($_POST['lastname'] ?? '')) ?>" placeholder="e.g. CRUZ"></div>
      <div><label>First name</label>
      <input type="text" name="firstname" required value="<?= e($first ?? ($_POST['firstname'] ?? '')) ?>" placeholder="e.g. JUAN"></div>
    </div>
    <label>Middle initial (optional)</label>
    <input type="text" name="mi" maxlength="3" value="<?= e($mi ?? ($_POST['mi'] ?? '')) ?>" placeholder="e.g. D">
    <label>Gender</label>
    <select name="gender" required>
      <option value="">-- Select --</option>
      <?php foreach (['Male','Female','Other'] as $g): ?>
        <option <?= (($_POST['gender'] ?? '') === $g) ? 'selected' : '' ?>><?= $g ?></option>
      <?php endforeach; ?>
    </select>
    <label>Section</label>
    <select name="section_id" required>
      <option value="">-- Select section --</option>
      <?php foreach ($sections as $s): ?>
        <option value="<?= $s['id'] ?>" <?= (($_POST['section_id'] ?? '') == $s['id']) ? 'selected' : '' ?>><?= e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <label>Username</label>
    <input type="text" name="username" required value="<?= e($_POST['username'] ?? '') ?>">
    <label>Password</label>
    <input type="password" name="password" required>
    <label>Confirm password</label>
    <input type="password" name="password2" required>
    <div class="btnrow"><button class="btn" type="submit">Create Account</button>
    <a class="btn ghost" href="index.php">Back to Login</a></div>
  </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
