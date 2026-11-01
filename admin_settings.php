<?php
// Admin settings: flip app-wide features on/off (currently: student self-registration)
// + convert existing account names to uppercase.
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/names.php';
$user = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_registration') {
        setting_set('registration_open', (($_POST['registration_open'] ?? '') === '1') ? '1' : '0');
        set_flash(registration_open()
            ? 'Student registration is now OPEN — the Register links are visible again.'
            : 'Student registration is now CLOSED — new sign-ups are blocked.');
    }

    if ($action === 'toggle_anti_cheat') {
        setting_set('anti_cheat_tab_switch', (($_POST['anti_cheat_tab_switch'] ?? '') === '1') ? '1' : '0');
        set_flash(anti_cheat_tab_switch()
            ? 'Anti-cheat is now ON — students get 3 warnings for switching tabs/apps, then the exam auto-submits.'
            : 'Anti-cheat is now OFF — students can switch tabs freely (no tracking, no warnings).');
    }

    if ($action === 'uppercase') {
        try {
            $roles = sanitize_roles((array)($_POST['roles'] ?? []));
            if (!$roles) {
                set_flash('Select at least one role (Students or Teachers).');
            } else {
                set_flash(uppercase_result_message(convert_names($roles)));
            }
        } catch (Throwable $ex) {
            set_flash('Conversion failed: ' . $ex->getMessage());
        }
    }

    header('Location: admin_settings.php'); exit;
}

$reg_open = registration_open();
$anti_cheat_on = anti_cheat_tab_switch();
// How many names are still mixed-case (so the button says what it will do).
$pending = [];
try { $pending = names_to_update(array_keys(selectable_roles())); }
catch (Throwable $ex) { $pending = []; }
$by_role = [];
foreach ($pending as $p) $by_role[$p['row']['role']] = ($by_role[$p['row']['role']] ?? 0) + 1;
$title = 'Settings';
include __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:640px">
  <h3 style="margin-top:0">⚙️ App Settings</h3>

  <form method="post" style="border-top:1px solid var(--line);padding-top:16px;margin-top:8px">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="toggle_registration">
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
  <h3 style="margin-top:0">🛡️ Anti-cheat (tab switching)</h3>
  <form method="post" style="border-top:1px solid var(--line);padding-top:16px;margin-top:8px">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="toggle_anti_cheat">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
      <div>
        <b>Tab-switch detection</b>
        <p class="hint" style="margin:4px 0 0">
          <?= $anti_cheat_on
              ? 'Currently <b style="color:var(--bad)">ON</b> — students get 3 warnings for switching tabs/apps/minimizing; the exam auto-submits on the next switch.'
              : 'Currently <b style="color:var(--ok)">OFF</b> — no tracking or warnings during exams.' ?>
        </p>
      </div>
      <div style="display:flex;align-items:center;gap:10px">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin:0;font-weight:600">
          <input type="checkbox" name="anti_cheat_tab_switch" value="1" <?= $anti_cheat_on ? 'checked' : '' ?>>
          <?= $anti_cheat_on ? 'On' : 'Off' ?>
        </label>
        <button class="btn" type="submit">Save</button>
      </div>
    </div>
    <p class="hint" style="margin-top:10px">
      Warning 1 and 2 show a banner; violation 3 submits the exam immediately.
      The attempt is flagged on Teacher → Review as <b>AUTO-SUBMITTED</b> with the switch count.
      Turn it off for phone-based or take-home exams — notifications and app switches would false-positive.
    </p>
  </form>
</div>

<div class="card" style="max-width:640px">
  <h3 style="margin-top:0">🔡 Convert existing names to UPPERCASE</h3>
  <?php if (!$pending): ?>
    <p style="margin:0"><b>✅ All student and teacher names are already uppercase — nothing to do.</b></p>
    <p class="hint" style="margin-top:6px">New accounts added from now on are stored uppercase automatically.</p>
  <?php else: ?>
    <p class="hint" style="margin-bottom:10px">
      <b><?= count($pending) ?></b> account<?= count($pending) === 1 ? '' : 's' ?> still have mixed-case names
      (<?= e(implode(', ', array_map(fn($role, $n) => $n . ' ' . $role . ($n === 1 ? '' : 's'), array_keys($by_role), $by_role))) ?>).
      Converting keeps the name readable — only the case changes.
    </p>
    <div class="table-wrap" style="max-height:260px;overflow:auto;margin-bottom:10px">
      <table style="width:100%;border-collapse:collapse;font-size:.85rem">
        <tr><th>Role</th><th>Before</th><th>After</th></tr>
        <?php foreach (array_slice($pending, 0, 25) as $p): ?>
        <tr>
          <td class="hint"><?= e(selectable_roles()[$p['row']['role']] ?? $p['row']['role']) ?></td>
          <td><?= e($p['row']['fullname']) ?></td>
          <td><b><?= e($p['new'][3]) ?></b></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php if (count($pending) > 25): ?><p class="hint">…and <?= count($pending) - 25 ?> more (all will be converted).</p><?php endif; ?>
    <form method="post" onsubmit="return confirm('Convert <?= count($pending) ?> name(s) to uppercase?')">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="uppercase">
      <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center">
        <?php foreach (selectable_roles() as $role => $label): ?>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin:0;font-weight:600">
          <input type="checkbox" name="roles[]" value="<?= e($role) ?>" checked> <?= e($label) ?>
          <?php if (!empty($by_role[$role])): ?><small class="hint">(<?= (int)$by_role[$role] ?>)</small><?php endif; ?>
        </label>
        <?php endforeach; ?>
        <button class="btn" type="submit">Convert <?= count($pending) ?> name(s)</button>
      </div>
    </form>
  <?php endif; ?>
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
