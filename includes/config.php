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
