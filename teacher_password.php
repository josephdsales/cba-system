<?php
// Teacher self-service password change (same rules as student_password.php).
// Passwords are stored as bcrypt (users.password_hash); the current password
// is verified against the DB because the session copy carries no hash.
// Admin can still reset any teacher password from admin_teachers.php.
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('teacher');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $cur = $_POST['current_password'] ?? '';
    $p1 = $_POST['password'] ?? '';
    $p2 = $_POST['password2'] ?? '';
    if ($cur === '') $error = 'Please enter your current password.';
    elseif (strlen($p1) < 6) $error = 'New password must be at least 6 characters.';
    elseif ($p1 !== $p2) $error = 'New passwords do not match.';
    else {
        try {
            $st = db()->prepare("SELECT password_hash FROM users WHERE id=? AND role='teacher'");
            $st->execute([(int)$user['id']]);
            $hash = $st->fetchColumn();
            if (!$hash || !password_verify($cur, $hash)) {
                $error = 'Current password is incorrect.';
            } elseif (password_verify($p1, $hash)) {
                $error = 'New password must be different from your current password.';
            } else {
                $st = db()->prepare("UPDATE users SET password_hash=? WHERE id=? AND role='teacher'");
                $st->execute([password_hash($p1, PASSWORD_DEFAULT), (int)$user['id']]);
                set_flash('Password changed. Use your new password the next time you log in.');
                header('Location: teacher_password.php'); exit;
            }
        } catch (PDOException $ex) {
            $error = 'Could not change password. Please try again.';
        }
    }
}

$title = 'Change Password';
include __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:520px">
  <h3 style="margin-top:0">Change your password</h3>
  <p class="hint">Logged in as <b><?= e($user['fullname']) ?></b> (<?= e($user['username']) ?>). Minimum 6 characters.</p>
  <?php if ($error): ?><p style="color:#b91c1c"><b><?= e($error) ?></b></p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label>Current password</label>
    <input type="password" name="current_password" autocomplete="current-password" required>
    <label>New password</label>
    <input type="password" name="password" autocomplete="new-password" minlength="6" required>
    <label>Confirm new password</label>
    <input type="password" name="password2" autocomplete="new-password" minlength="6" required>
    <div class="btnrow">
      <button class="btn" type="submit">Change password</button>
      <a class="btn ghost" href="dashboard.php">Cancel</a>
    </div>
  </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
