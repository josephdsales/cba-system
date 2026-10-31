<?php
// One-time maintenance: convert EXISTING names to UPPERCASE, matching what
// register.php now stores for new student sign-ups. Pick Students and/or
// Teachers. Safe to run repeatedly (already-uppercase rows are skipped).
// Afterwards DELETE this file.
//
// Handles legacy accounts whose lastname/firstname are still NULL (created
// before the name columns were split) by splitting fullname on the first comma.
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
require_role('admin');

// Roles this script may convert. Keys = the DB value, values = label.
function selectable_roles(): array {
    return ['student' => 'Students', 'teacher' => 'Teachers'];
}

// Returns [lastname, firstname, mi, fullname] in uppercase for a user row.
function uppercase_user_name(array $r): array {
    $last = (string)($r['lastname'] ?? '');
    $first = (string)($r['firstname'] ?? '');
    $mi = (string)($r['mi'] ?? '');
    $full = (string)($r['fullname'] ?? '');

    // Old accounts may have no split columns — derive them from fullname.
    if ($last === '' && $first === '' && strpos($full, ',') !== false) {
        [$l, $rest] = explode(',', $full, 2);
        $last = $l;
        $first = trim($rest);
        // Pull a trailing initial off the first name (e.g. "JUAN D." -> "JUAN" + "D.")
        if (preg_match('/^(.*?)(?:\s+([A-Za-z]\.)\s*)$/', $first, $m)) {
            $first = $m[1];
            if ($mi === '') $mi = $m[2];
        }
    }

    $last = to_upper_name(trim($last));
    $first = to_upper_name(trim($first));
    $mi = to_upper_name(trim($mi));
    $mi = $mi !== '' ? rtrim($mi, '.') . '.' : '';

    if ($last === '' && $first === '') {
        // Nothing split-able — just uppercase the stored full name as-is.
        return [null, null, ($mi !== '' ? $mi : null), to_upper_name(trim($full))];
    }
    return [$last, $first, ($mi !== '' ? $mi : null), $last . ', ' . $first . ($mi !== '' ? ' ' . $mi : '')];
}

// Keep only roles this script is allowed to touch.
function sanitize_roles(array $roles): array {
    return array_values(array_unique(array_intersect(array_map('strval', $roles), array_keys(selectable_roles()))));
}

// Rows in the given roles whose name is not yet fully uppercase.
function names_to_update(array $roles): array {
    $roles = sanitize_roles($roles);
    if (!$roles) return [];
    $ph = implode(',', array_fill(0, count($roles), '?'));
    $st = db()->prepare("SELECT id, role, fullname, lastname, firstname, mi FROM users WHERE role IN ($ph) ORDER BY role, id");
    $st->execute($roles);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $new = uppercase_user_name($r);
        $changed = ($new[0] !== (string)($r['lastname'] ?? '')) || ($new[1] !== (string)($r['firstname'] ?? ''))
            || ($new[3] !== (string)($r['fullname'] ?? ''));
        if ($changed) $out[] = ['row' => $r, 'new' => $new];
    }
    return $out;
}

// POST -> convert -> redirect (PRG, so a refresh can't re-run it).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['action'] ?? '') === 'uppercase') {
        $selected = sanitize_roles((array)($_POST['roles'] ?? []));
        if (!$selected) {
            set_flash('Select at least one role (Students or Teachers).');
        } else {
            try {
                $pending = names_to_update($selected);
                $st = db()->prepare('UPDATE users SET lastname=?, firstname=?, mi=?, fullname=? WHERE id=?');
                $per = [];
                foreach ($pending as $p) {
                    $st->execute([$p['new'][0], $p['new'][1], $p['new'][2], $p['new'][3], $p['row']['id']]);
                    $per[$p['row']['role']] = ($per[$p['row']['role']] ?? 0) + 1;
                }
                if ($per) {
                    $bits = [];
                    foreach ($per as $role => $n) $bits[] = $n . ' ' . $role . ($n === 1 ? '' : 's');
                    set_flash('Done — ' . implode(' and ', $bits) . ' converted to uppercase.');
                } else {
                    set_flash('Nothing to convert — those names are already uppercase.');
                }
            } catch (Throwable $ex) {
                set_flash('Failed: ' . $ex->getMessage());
            }
        }
        header('Location: uppercase_names.php'); exit;
    }
}

$all = selectable_roles();
// Preview shows every selectable role; the form defaults to both checked.
$selected = array_keys($all);
try { $pending = names_to_update($selected); }
catch (Throwable $ex) { $pending = []; set_flash('Could not read users table: ' . $ex->getMessage()); }

$title = 'Uppercase Names';
include __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:760px;margin:20px auto;">
  <h3 style="margin-top:0">Uppercase names</h3>
  <p class="hint"><b>Run once.</b> Converts existing names to uppercase so they match names stored at registration. Safe to re-run — already-uppercase rows are skipped. Afterwards <b>delete this file</b>.</p>

  <form method="post" style="border-top:1px solid var(--line);padding-top:14px;margin-top:10px">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="uppercase">
    <label style="margin-bottom:6px">Convert which accounts?</label>
    <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:12px">
      <?php foreach ($all as $role => $label): $n = $role === 'student' ? 'students' : 'teachers'; ?>
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin:0;font-weight:600">
        <input type="checkbox" name="roles[]" value="<?= e($role) ?>" checked> <?= e($label) ?>
        <small class="hint">(existing <?= e($n) ?>)</small>
      </label>
      <?php endforeach; ?>
    </div>

    <?php if (!$pending): ?>
      <p style="margin:0"><b>✅ Nothing to convert — all selected names are already uppercase.</b></p>
    <?php else: ?>
      <p style="margin:0 0 8px"><b><?= count($pending) ?></b> name(s) will change:</p>
      <div class="table-wrap" style="max-height:320px;overflow:auto">
        <table>
          <tr><th>Role</th><th>Before</th><th>After</th></tr>
          <?php foreach (array_slice($pending, 0, 50) as $p): ?>
          <tr>
            <td class="hint"><?= e($all[$p['row']['role']] ?? $p['row']['role']) ?></td>
            <td><?= e($p['row']['fullname']) ?></td>
            <td><b><?= e($p['new'][3]) ?></b></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?php if (count($pending) > 50): ?><p class="hint">…and <?= count($pending) - 50 ?> more.</p><?php endif; ?>
      <div class="btnrow" style="margin-top:12px">
        <button class="btn" type="submit" onclick="return confirm('Convert <?= count($pending) ?> name(s) to uppercase?')">Convert <?= count($pending) ?> name(s)</button>
        <a class="btn ghost" href="dashboard.php">Cancel</a>
      </div>
    <?php endif; ?>
  </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
