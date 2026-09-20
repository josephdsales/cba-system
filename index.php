<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';

if (current_user()) { header('Location: dashboard.php'); exit; }
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    
    // Rate limiting: max 5 failed logins per IP per 15 minutes
    $ip = $_SERVER['REMOTE_ADDR'];
    $key = 'login_fail_' . $ip;
    $fails = (int)($_SESSION[$key]['count'] ?? 0);
    $last_fail = (int)($_SESSION[$key]['time'] ?? 0);
    $now = time();
    if ($now - $last_fail > 900) { // 15 minutes reset
        $fails = 0;
    }
    if ($fails >= 5) {
        $error = 'Too many failed attempts. Please wait 15 minutes.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        try {
        $st = db()->prepare('SELECT u.*, s.name AS section_name FROM users u LEFT JOIN sections s ON s.id=u.section_id WHERE u.username=?');
        $st->execute([$username]);
        $u = $st->fetch();
        if ($u && password_verify($password, $u['password_hash'])) {
            unset($u['password_hash']);
            session_regenerate_id(true);
            $_SESSION['user'] = $u;
            unset($_SESSION['login_fail_' . $ip]); // Clear fail count on success
            header('Location: dashboard.php'); exit;
        }
        // Failed login
        $_SESSION[$key] = ['count' => $fails + 1, 'time' => $now];
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
