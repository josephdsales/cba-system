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
        if (!hash_equals($_SESSION['csrf'] ?? '', $t)) {
            http_response_code(419);
            exit('Invalid request token. Go back and try again.');
        }
    }
}

// Sections visible to a teacher: own + assigned by admin + shared.
// Admins don't use this (they see everything).
function visible_sections(int $teacher_id): array {
    $st = db()->prepare("SELECT s.*, t.fullname AS teacher_name,
        (SELECT COUNT(*) FROM users u WHERE u.section_id=s.id) AS student_count
        FROM sections s LEFT JOIN users t ON t.id=s.assigned_teacher_id
        WHERE s.assigned_teacher_id IS NULL OR s.assigned_teacher_id=? OR s.created_by=?
        ORDER BY s.name");
    $st->execute([$teacher_id, $teacher_id]);
    return $st->fetchAll();
}
