<?php
// Admin settings: flip app-wide features on/off (currently: student self-registration).
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/settings.php';
$user = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    setting_set('registration_open', (($_POST['registration_open'] ?? '') === '1') ? '1' : '0');
    set_flash(registration_open()
        ? 'Student registration is now OPEN — the Register links are visible again.'
        : 'Student registration is now CLOSED — new sign-ups are blocked.');
    header('Location: admin_settings.php'); exit;
}

$reg_open = registration_open();
$title = 'Settings';
include __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:640px">
  <h3 style="margin-top:0">⚙️ App Settings</h3>

  <form method="post" style="border-top:1px solid var(--line);padding-top:16px;margin-top:8px">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
      <div>
        <b>Student registration</b>
        <p class="hint" style="margin:4px 0 0">
          <?= $reg_open
              ? 'Currently <b style="color:var(--ok)">OPEN</b> — anyone can create a student account from the login page.'
              : 'Currently <b style="color:var(--bad)">CLOSED</b> — the Register links are hidden and sign-ups are blocked. Students must be added by an admin.' ?>
        </p>
      </div>
      <div style="display:flex;align-items:center;gap:10px">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin:0;font-weight:600">
          <input type="checkbox" name="registration_open" value="1" <?= $reg_open ? 'checked' : '' ?>>
          <?= $reg_open ? 'On' : 'Off' ?>
        </label>
        <button class="btn" type="submit">Save</button>
      </div>
    </div>
    <p class="hint" style="margin-top:10px">Applies immediately to the login page, the top menu, and <code>register.php</code> (which refuses submissions while closed).</p>
  </form>
</div>

<div class="card" style="max-width:640px">
  <h3 style="margin-top:0">➕ Adding students while registration is closed</h3>
  <p class="hint" style="margin-bottom:8px">Students can still be created in two ways:</p>
  <ul class="hint" style="margin:0;padding-left:20px;line-height:1.7">
    <li><a href="admin_students.php">Admin → Students</a> — add or edit student accounts directly.</li>
    <li>Ask the student to register while the switch is <b>On</b>, then switch it back <b>Off</b>.</li>
  </ul>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
