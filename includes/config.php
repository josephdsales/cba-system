<?php
// =============================================
// CBA System - configuration
// Works BOTH ways:
//  - Classic hosting (cPanel/XAMPP): set DB_HOST / DB_NAME / DB_USER / DB_PASS
//    below or as env vars  -> MySQL.
//  - Neon (free, external): set DATABASE_URL env var in the
//    Render dashboard -> Postgres. Render's free Postgres expires,
//    so the database lives outside Render.
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

// Connect to a Postgres URL (Neon/Render style: postgres://user:pass@host:port/db?sslmode=require)
function pdo_from_url(string $url): PDO {
    $u = parse_url($url);
    if ($u === false || empty($u['host'])) {
        throw new RuntimeException('Invalid connection URL: host missing');
    }
    $host = $u['host'];
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
    return $pdo;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (getenv('DATABASE_URL')) {
            $pdo = pdo_from_url(getenv('DATABASE_URL'));
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

// =============================================
// Database-backed session handler
// Survives Render free-tier spin-down (ephemeral filesystem wiped on sleep)
// =============================================
class DBSessionHandler implements SessionHandlerInterface {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function open($path, $name): bool { return true; }
    public function close(): bool { return true; }

    public function read($id): string {
        $st = $this->pdo->prepare('SELECT data FROM sessions WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ? $row['data'] : '';
    }

    public function write($id, $data): bool {
        $now = time();
        $user_id = $_SESSION['user']['id'] ?? null;
        $st = $this->pdo->prepare(
            'INSERT INTO sessions (id, user_id, data, last_activity) VALUES (?, ?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, last_activity = EXCLUDED.last_activity, user_id = EXCLUDED.user_id'
        );
        // Use the appropriate UPSERT syntax for MySQL
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $st = $this->pdo->prepare(
                'INSERT INTO sessions (id, user_id, data, last_activity) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), last_activity = VALUES(last_activity), user_id = VALUES(user_id)'
            );
        }
        return $st->execute([$id, $user_id, $data, $now]);
    }

    public function destroy($id): bool {
        $st = $this->pdo->prepare('DELETE FROM sessions WHERE id = ?');
        return $st->execute([$id]);
    }

    public function gc($max_lifetime): int|false {
        $cutoff = time() - $max_lifetime;
        $st = $this->pdo->prepare('DELETE FROM sessions WHERE last_activity < ?');
        $st->execute([$cutoff]);
        return $st->rowCount();
    }
}

// Try to use database sessions; fall back to file-based if sessions table missing
try {
    $test = db()->query("SELECT 1 FROM sessions LIMIT 1");
    $handler = new DBSessionHandler(db());
    session_set_save_handler($handler, true);
} catch (PDOException $e) {
    // Sessions table doesn't exist yet (install.php hasn't run) — use default file sessions
}

// Start session (after handler is registered)
if (session_status() === PHP_SESSION_NONE) session_start();
