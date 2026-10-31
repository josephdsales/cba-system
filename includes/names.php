<?php
// Name-case helpers: convert existing account names to UPPERCASE so they match
// what register.php / admin_teachers.php / admin_students.php now store.
// Used by admin_settings.php (the admin UI) — no separate script to hunt down.
//
// Depends on config.php (db) and auth.php (to_upper_name). Requires both.

// Roles this tool may convert. Keys = the DB value, values = label.
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

// Keep only roles this tool is allowed to touch (blocks junk/SQL in the field).
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

// Convert pending rows. Returns per-role counts: ['student'=>12, 'teacher'=>3].
function convert_names(array $roles): array {
    $pending = names_to_update($roles);
    $st = db()->prepare('UPDATE users SET lastname=?, firstname=?, mi=?, fullname=? WHERE id=?');
    $per = [];
    foreach ($pending as $p) {
        $st->execute([$p['new'][0], $p['new'][1], $p['new'][2], $p['new'][3], $p['row']['id']]);
        $per[$p['row']['role']] = ($per[$p['row']['role']] ?? 0) + 1;
    }
    return $per;
}

// Human message from convert_names() output.
function uppercase_result_message(array $per): string {
    if (!$per) return 'Nothing to convert — those names are already uppercase.';
    $bits = [];
    foreach ($per as $role => $n) $bits[] = $n . ' ' . $role . ($n === 1 ? '' : 's');
    return 'Done — ' . implode(' and ', $bits) . ' converted to uppercase.';
}
