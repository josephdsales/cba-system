<?php
// =============================================
// TEMPORARY migration tool — copies every table from the old, expiring
// Render Postgres (OLD_DATABASE_URL) into the new free Neon database
// (NEW_DATABASE_URL), preserving IDs, then verifies row counts.
//
// Open:  /db_migrate.php?key=YOUR_MIGRATE_KEY   (or as logged-in admin)
//
// After counts match: switch DATABASE_URL in the Render dashboard to the
// Neon string, verify the app, then DELETE this file.
// =============================================
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/schema.php';

set_time_limit(0);
ignore_user_abort(true);

$old_url = getenv('OLD_DATABASE_URL') ?: '';
$new_url = getenv('NEW_DATABASE_URL') ?: '';
$migrate_key = getenv('MIGRATE_KEY') ?: '';

$u = current_user();
$authorized = ($u && ($u['role'] ?? '') === 'admin');
if (!$authorized && $migrate_key !== '' && isset($_GET['key'])
    && hash_equals($migrate_key, (string)$_GET['key'])) {
    $authorized = true;
}
if (!$authorized) {
    http_response_code(404);
    exit('Not found');
}

$tables = ['sections', 'users', 'exams', 'exam_students', 'section_teachers',
           'questions', 'attempts', 'answers', 'sessions'];

function same_database(string $a, string $b): bool {
    $x = parse_url($a);
    $y = parse_url($b);
    return ($x['host'] ?? '') === ($y['host'] ?? '')
        && ($x['path'] ?? '') === ($y['path'] ?? '')
        && ($x['user'] ?? '') === ($y['user'] ?? '');
}

function copy_all(PDO $src, PDO $tgt, array $tables): array {
    ensure_schema($tgt);

    // Clear the target first (children before parents) so re-runs are clean.
    foreach (array_reverse($tables) as $t) {
        try { $tgt->exec('DELETE FROM "' . $t . '"'); } catch (Throwable $e) { /* table missing */ }
    }

    // Copy parents before children (foreign-key order).
    $tgt->beginTransaction();
    try {
        foreach ($tables as $t) {
            $rows = $src->query('SELECT * FROM "' . $t . '"')->fetchAll();
            foreach (array_chunk($rows, 100) as $chunk) {
                $cols = array_map(function ($c) { return '"' . $c . '"'; }, array_keys($chunk[0]));
                $tuple = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
                $vals = implode(', ', array_fill(0, count($chunk), $tuple));
                $ins = $tgt->prepare(
                    'INSERT INTO "' . $t . '" (' . implode(', ', $cols) . ') VALUES ' . $vals .
                    ' ON CONFLICT DO NOTHING'
                );
                $params = [];
                foreach ($chunk as $row) {
                    foreach ($row as $v) $params[] = $v;
                }
                $ins->execute($params);
            }
        }
        $tgt->commit();
    } catch (Throwable $e) {
        $tgt->rollBack();
        throw $e;
    }

    // Reset auto-increment sequences so new rows get correct IDs.
    foreach (['sections', 'users', 'exams', 'questions', 'attempts', 'answers'] as $t) {
        try {
            $tgt->exec("SELECT setval(pg_get_serial_sequence('$t', 'id'),"
                . " COALESCE((SELECT MAX(id) FROM \"$t\"), 0) + 1, false)");
        } catch (Throwable $e) { /* not a serial table */ }
    }

    // Verify: row counts must match on every table.
    $report = [];
    foreach ($tables as $t) {
        $old_n = (int)$src->query('SELECT COUNT(*) FROM "' . $t . '"')->fetchColumn();
        $new_n = (int)$tgt->query('SELECT COUNT(*) FROM "' . $t . '"')->fetchColumn();
        $report[] = ['table' => $t, 'old' => $old_n, 'new' => $new_n, 'ok' => $old_n === $new_n];
    }
    return $report;
}

$msg = ''; $err = ''; $report = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['do'] ?? '') === 'migrate') {
        if ($old_url === '' || $new_url === '') {
            $err = 'Set BOTH OLD_DATABASE_URL and NEW_DATABASE_URL in the Render dashboard first.';
        } elseif (same_database($old_url, $new_url)) {
            $err = 'OLD and NEW connection strings point at the same database — aborting to avoid data loss.';
        } else {
            try {
                $report = copy_all(pdo_from_url($old_url), pdo_from_url($new_url), $tables);
                $bad = array_filter($report, function ($r) { return !$r['ok']; });
                if ($bad) {
                    $err = 'Copied, but some row counts differ — run the migration again.';
                } else {
                    $msg = 'Migration complete — all row counts match. Next: in the Render dashboard set '
                        . 'DATABASE_URL to your NEW (Neon) connection string, verify the app, '
                        . 'then delete this file.';
                }
            } catch (Throwable $ex) {
                $err = 'Migration failed: ' . $ex->getMessage();
            }
        }
    }
}

function db_host_of(string $url): string {
    $h = parse_url($url, PHP_URL_HOST);
    return $h !== null && $h !== false ? $h : '(not set)';
}

$title = 'Database Migration';
$user = current_user();
include __DIR__ . '/includes/header.php';
?>
<div class="card" style="max-width:640px;margin:20px auto;">
  <p class="hint">Copies <b>old Render Postgres → new Neon database</b>. Run it once, check the counts, then switch <code>DATABASE_URL</code>.</p>
  <p class="hint">
    Source (old): <b><?= e(db_host_of($old_url)) ?></b><br>
    Target (new): <b><?= e(db_host_of($new_url)) ?></b>
  </p>
  <?php if ($err): ?><div class="flash"><?= e($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($report): ?>
    <table class="table">
      <tr><th>Table</th><th>Old</th><th>New</th><th>OK</th></tr>
      <?php foreach ($report as $r): ?>
      <tr>
        <td><?= e($r['table']) ?></td>
        <td><?= (int)$r['old'] ?></td>
        <td><?= (int)$r['new'] ?></td>
        <td><?= $r['ok'] ? '✔' : '✖' ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?php if (!$msg): ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="migrate">
    <div class="btnrow"><button class="btn" type="submit">Copy data now</button></div>
  </form>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
