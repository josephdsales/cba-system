<?php
// Session + auth helpers. Include after config.php
if (session_status() === PHP_SESSION_NONE) session_start();

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) { header('Location: index.php'); exit; }
    return $u;
}

function require_role($roles): array {
    $u = require_login();
    $roles = (array)$roles;
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('Forbidden: your account (' . htmlspecialchars($u['role']) . ') cannot access this page.');
    }
    return $u;
}

function refresh_session_user(int $id): void {
    $st = db()->prepare('SELECT u.*, s.name AS section_name FROM users u LEFT JOIN sections s ON s.id=u.section_id WHERE u.id=?');
    $st->execute([$id]);
    if ($row = $st->fetch()) {
        unset($row['password_hash']);
        $_SESSION['user'] = $row;
    }
}

function flash(string $key = 'msg'): ?string {
    if (isset($_SESSION['flash_' . $key])) {
        $m = $_SESSION['flash_' . $key];
        unset($_SESSION['flash_' . $key]);
        return $m;
    }
    return null;
}

function set_flash(string $msg, string $key = 'msg'): void {
    $_SESSION['flash_' . $key] = $msg;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function check_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $t = $_POST['csrf'] ?? '';
        // If session is empty/lost (expired, spin-down, multi-tab), regenerate token silently
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            return;
        }
        if (!hash_equals($_SESSION['csrf'] ?? '', $t)) {
            set_flash('Session expired. Please try again.');
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
            exit;
        }
    }
}

// Names are stored UPPERCASE regardless of how they were typed.
// mb_strtoupper handles accented Filipino names (ñ, á, ...) — plain
// strtoupper() only maps ASCII letters and would leave them lowercase.
function to_upper_name(string $s): string {
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}

// Case-insensitive duplicate-username check. MySQL's default _ci collations
// already compare usernames case-insensitively, but Postgres (Neon) is
// case-sensitive — so all username comparisons use LOWER() on both sides.
// Excludes $exclude_id so a user keeps their own username when editing.
function username_taken(string $username, int $exclude_id = 0): bool {
    $st = db()->prepare('SELECT 1 FROM users WHERE LOWER(username)=LOWER(?) AND id<>?');
    $st->execute([$username, $exclude_id]);
    return (bool)$st->fetch();
}

// Sections visible to a teacher: all sections, but they can only edit assigned ones.
// Admins don't use this (they see everything).
function visible_sections(int $teacher_id): array {
    $concatFn = (db_driver() === 'pgsql') ? 'STRING_AGG(t.fullname, \', \' ORDER BY t.fullname)' : 'GROUP_CONCAT(t.fullname ORDER BY t.fullname)';
    $st = db()->prepare("SELECT s.*, 
        (SELECT COUNT(*) FROM users u WHERE u.section_id=s.id) AS student_count,
        (SELECT $concatFn 
         FROM section_teachers st 
         JOIN users t ON t.id=st.teacher_id 
         WHERE st.section_id=s.id) AS teacher_names
        FROM sections s 
        LEFT JOIN section_teachers st ON st.section_id=s.id
        WHERE st.teacher_id IS NULL OR st.teacher_id=?
        ORDER BY s.name");
    $st->execute([$teacher_id]);
    return $st->fetchAll();
}
