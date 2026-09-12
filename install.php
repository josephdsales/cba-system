<?php
// First-run installer: creates tables (if missing) + the single admin account.
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';

$msg = ''; $done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $admin_user = trim($_POST['admin_user'] ?? 'admin');
    $admin_pass = $_POST['admin_pass'] ?? '';
    $admin_name = trim($_POST['admin_name'] ?? 'System Administrator');
    if (strlen($admin_user) < 3 || strlen($admin_pass) < 6) {
        $msg = 'Admin username min 3 chars, password min 6 chars.';
    } else {
        try {
            $schema = db_driver() === 'pgsql' ? 'schema_pgsql.sql' : 'schema.sql';
            $sql = file_get_contents(__DIR__ . '/database/' . $schema);
            // Strip full-line -- comments BEFORE splitting, so the first
            // statement is not mistaken for a comment block and skipped.
            $sql = preg_replace('/^--[^\n]*$/m', '', $sql);
            // Run schema statement-by-statement (PDO::exec can't do multi-statements reliably)
            $stmts = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($stmts as $s) {
                if ($s === '') continue;
                db()->exec($s);
            }
            // v2 upgrade (idempotent): section -> teacher assignment.
            // Teacher-created sections become visible only to their creator.
            try { db()->exec('ALTER TABLE sections ADD COLUMN assigned_teacher_id INT'); } catch (Throwable $e) { /* column exists */ }
            try { db()->exec("UPDATE sections SET assigned_teacher_id=created_by WHERE assigned_teacher_id IS NULL AND created_by IN (SELECT id FROM users WHERE role='teacher')"); } catch (Throwable $e) { /* nothing to backfill */ }
            // v3 upgrade (idempotent): split name columns for last-name sorting.
            try { db()->exec('ALTER TABLE users ADD COLUMN lastname VARCHAR(100)'); } catch (Throwable $e) { /* exists */ }
            try { db()->exec('ALTER TABLE users ADD COLUMN firstname VARCHAR(100)'); } catch (Throwable $e) { /* exists */ }
            try { db()->exec('ALTER TABLE users ADD COLUMN mi VARCHAR(10)'); } catch (Throwable $e) { /* exists */ }
            try {
                if (db_driver() === 'pgsql') {
                    db()->exec("UPDATE users SET lastname=TRIM(SPLIT_PART(fullname, ',', 1)), firstname=TRIM(SPLIT_PART(fullname, ',', 2)) WHERE fullname LIKE '%,%' AND (lastname IS NULL OR lastname='')");
                } else {
                    db()->exec("UPDATE users SET lastname=TRIM(SUBSTRING_INDEX(fullname, ',', 1)), firstname=TRIM(SUBSTRING(fullname, LOCATE(',', fullname)+1)) WHERE fullname LIKE '%,%' AND (lastname IS NULL OR lastname='')");
                }
            } catch (Throwable $e) { /* nothing to backfill */ }
            $hash = password_hash($admin_pass, PASSWORD_DEFAULT);
            // Portable upsert (works on MySQL and Postgres): update if username exists, else insert.
            $st = db()->prepare('SELECT id FROM users WHERE username=?');
            $st->execute([$admin_user]);
            if ($row = $st->fetch()) {
                $st = db()->prepare("UPDATE users SET fullname=?, password_hash=?, role='admin' WHERE id=?");
                $st->execute([$admin_name, $hash, $row['id']]);
            } else {
                $st = db()->prepare("INSERT INTO users (fullname, gender, username, password_hash, role)
                    VALUES (?, 'Other', ?, ?, 'admin')");
                $st->execute([$admin_name, $admin_user, $hash]);
            }
            $msg = "Setup complete. Admin account '{$admin_user}' is ready. DELETE install.php now, then Login.";
            $done = true;
        } catch (Throwable $ex) {
            $msg = 'Setup failed: ' . $ex->getMessage();
        }
    }
}
$title = 'Install'; $user = current_user();
include __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:520px;margin:20px auto;">
  <p class="hint"><b>Run once</b> on a fresh hosting account: creates tables + the 1 admin account. Afterwards <b>delete this file</b>.</p>
  <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
  <?php if (!$done): ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label>Admin full name</label>
    <input type="text" name="admin_name" value="System Administrator" required>
    <label>Admin username</label>
    <input type="text" name="admin_user" value="admin" required>
    <label>Admin password (min 6 chars)</label>
    <input type="password" name="admin_pass" required>
    <div class="btnrow"><button class="btn" type="submit">Run Setup</button></div>
  </form>
  <?php else: ?>
    <div class="btnrow"><a class="btn" href="index.php">Go to Login</a></div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
