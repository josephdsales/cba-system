<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';

if (current_user()) { header('Location: dashboard.php'); exit; }
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    try {
        $st = db()->prepare('SELECT u.*, s.name AS section_name FROM users u LEFT JOIN sections s ON s.id=u.section_id WHERE u.username=?');
        $st->execute([$username]);
        $u = $st->fetch();
        if ($u && password_verify($password, $u['password_hash'])) {
            unset($u['password_hash']);
            $_SESSION['user'] = $u;
            header('Location: dashboard.php'); exit;
        }
        $error = 'Invalid username or password.';
    } catch (PDOException $ex) {
        $error = 'Database not ready. Import database/schema.sql, check includes/config.php, then open install.php. (' . $ex->getMessage() . ')';
    }
}

$title = 'Login'; $user = null;
include __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:480px;margin:20px auto;">
  <h2 style="margin-top:0">Welcome 👋</h2>
  <p class="hint">One login for Admin, Teachers, and Students. New student? <a href="register.php">Create an account</a>.</p>
  <?php if ($error): ?><p style="color:#b91c1c"><b><?= e($error) ?></b></p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label>Username</label>
    <input type="text" name="username" required autocomplete="username" autofocus>
    <label>Password</label>
    <input type="password" name="password" required autocomplete="current-password">
    <div class="btnrow"><button class="btn" type="submit">Login</button>
    <a class="btn ghost" href="register.php">Student Registration</a></div>
  </form>

</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
