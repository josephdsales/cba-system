<?php
// Superseded: this one-time script now lives inside Admin → Settings, so there's
// no separate URL to remember and nothing to delete afterwards.
// Kept only as a redirect in case this file was already uploaded/deployed.
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
require_role('admin');
header('Location: admin_settings.php', true, 302);
exit;
