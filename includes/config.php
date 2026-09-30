<?php
// =============================================
// CBA System - configuration
// Works BOTH ways:
//  - Classic hosting (cPanel/XAMPP): set DB_HOST / DB_NAME / DB_USER / DB_PASS
//    below or as env vars  -> MySQL.
//  - Render: attach the PostgreSQL database, which
//    provides DATABASE_URL automatically -> Postgres.
// Files can be redeployed any time; data is safe
// because it lives in the database, not in files.
// =============================================
define('APP_NAME', 'Computer-Based Assessment');
define('BASE_URL', ''); // leave empty = auto-detect. e.g. 'https://yourschool.com/cba'

// Set default timezone
date_default_timezone_set('Asia/Manila'); // Change to your timezone

// Session configuration — critical for Render (reverse proxy + ephemeral filesystem)
ini_set('session.gc_maxlifetime', 14400);       // 4 hours server-side
ini_set('session.cookie_lifetime', 14400);      // 4 hours cookie
ini_set('session.use_strict_mode', 1);          // Reject uninitialized session IDs
ini_set('session.use_only_cookies', 1);         // No session ID in URL
ini_set('session.cookie_httponly', 1);          // Prevent JS access to cookie
ini_set('session.cookie_samesite', 'Lax');      // Allow same-site redirects
session_set_cookie_params([
    'lifetime' => 14400,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);

function db_driver(): string {
    if (getenv('DATABASE_URL')) return 'pgsql';
    return 'mysql';
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (getenv('DATABASE_URL')) {
            // Render Postgres, e.g. postgres://user:pass@host:5432/db?sslmode=require
            $u = parse_url(getenv('DATABASE_URL'));
            $host = $u['host'] ?? 'localhost';
            $port = $u['port'] ?? 5432;
            $dbname = ltrim($u['path'] ?? '/cba_system', '/');
            $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
            if (!empty($u['query']) && strpos($u['query'], 'sslmode=') !== false) {
                parse_str($u['query'], $q);
                $dsn .= ';sslmode=' . ($q['sslmode'] ?? 'require');
            }
            $pdo = new PDO($dsn, $u['user'] ?? '', $u['pass'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // Sync DB timezone with PHP timezone
            $tz_offset = date('Z') / 3600;
            $tz_str = ($tz_offset >= 0 ? '+' : '') . $tz_offset . ':00';
            $pdo->exec("SET TIME ZONE INTERVAL '$tz_str' HOUR TO MINUTE");
        } else {
            $host = getenv('DB_HOST') ?: 'localhost';
            $name = getenv('DB_NAME') ?: 'cba_system';
            $user = getenv('DB_USER') ?: 'root';
            $pass = getenv('DB_PASS') ?: '';
            $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // Sync DB timezone with PHP timezone
            $tz_offset = date('Z') / 3600;
            $tz_str = ($tz_offset >= 0 ? '+' : '') . $tz_offset . ':00';
            $pdo->exec("SET time_zone = '$tz_str'");
        }
    }
    return $pdo;
}

// Local cPanel/XAMPP defaults (ignored on Render when DATABASE_URL exists)
if (!getenv('DATABASE_URL')) {
    if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'cba_system');
    if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
    if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');
}

function base_url(string $path = ''): string {
    if (BASE_URL !== '') return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
    return $path === '' ? '' : $path;
}

function e($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
