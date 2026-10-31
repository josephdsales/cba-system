<?php
// App settings: a tiny name/value table so admins can flip features on/off
// without editing code. Created lazily on first use — the same runtime
// migration pattern as ensure_arrangingsteps() in includes/schema.php,
// so no install.php/db_migrate.php re-run is needed.
//
// Do NOT require includes/schema.php from here: install.php and db_migrate.php
// include schema.php with a plain `require`, and a second load would fatal on
// redeclared functions.

function ensure_settings(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec('CREATE TABLE IF NOT EXISTS settings (name VARCHAR(64) PRIMARY KEY, value TEXT)');
    } catch (Throwable $e) { /* DB not ready — reads fall back to defaults */ }
}

// Per-request cache of every setting (one small query).
function &settings_cache(): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT name, value FROM settings') as $r) {
                $cache[$r['name']] = (string)$r['value'];
            }
        } catch (Throwable $e) { /* table missing/unreadable — empty = defaults */ }
    }
    return $cache;
}

function setting_get(string $name, string $default = ''): string {
    ensure_settings();
    $cache = &settings_cache();
    return array_key_exists($name, $cache) ? $cache[$name] : $default;
}

function setting_set(string $name, string $value): void {
    ensure_settings();
    $sql = (db_driver() === 'pgsql')
        ? 'INSERT INTO settings (name, value) VALUES (?, ?) ON CONFLICT (name) DO UPDATE SET value = EXCLUDED.value'
        : 'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)';
    db()->prepare($sql)->execute([$name, $value]);
    $cache = &settings_cache();
    $cache[$name] = $value;
}

// Student self-registration switch. Defaults to OPEN so behaviour is
// unchanged until an admin turns it off.
function registration_open(): bool {
    return setting_get('registration_open', '1') !== '0';
}
