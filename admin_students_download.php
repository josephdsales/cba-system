<?php
// Admin download: student roster (same filters/sort as the grid) as Excel (CSV)
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
require_role('admin');

$q = trim($_GET['q'] ?? '');
$section_filter = trim($_GET['section'] ?? '');
$sort = $_GET['sort'] ?? 'name';
if (!in_array($sort, ['name', 'gender', 'section', 'username', 'created'], true)) $sort = 'name';
$sdir = ($_GET['dir'] ?? 'asc') === 'asc' ? 'asc' : 'desc';

$order_map = [
    'name' => '(u.lastname IS NULL), u.lastname, u.firstname, u.fullname',
    'gender' => 'u.gender',
    'section' => 's.name',
    'username' => 'u.username',
    'created' => 'u.created_at'
];
$order_sql = $order_map[$sort] . ' ' . strtoupper($sdir);

$where = "u.role='student'";
$params = [];
$likeOp = (db_driver() === 'pgsql') ? 'ILIKE' : 'LIKE';
if ($q !== '') {
    $where .= " AND (u.fullname $likeOp ? OR u.username $likeOp ? OR LOWER(u.gender) = LOWER(?) OR COALESCE(u.lastname, '') $likeOp ? OR COALESCE(u.firstname, '') $likeOp ?)";
    $params = ["%$q%", "%$q%", $q, "%$q%", "%$q%"];
}
if ($section_filter !== '') {
    $where .= " AND s.name $likeOp ?";
    $params[] = "%$section_filter%";
}

$st = db()->prepare("SELECT u.fullname, u.gender, s.name AS section_name, u.username
                     FROM users u LEFT JOIN sections s ON s.id=u.section_id
                     WHERE $where ORDER BY $order_sql");
$st->execute($params);
$rows = $st->fetchAll();

$filename = 'Students-' . preg_replace('/[^A-Za-z0-9-_]+/', '_', $section_filter !== '' ? $section_filter : 'AllSections') . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, must-revalidate');

$out = fopen('php://output', 'w');

// UTF-8 BOM for Excel
fwrite($out, "\xEF\xBB\xBF");

// Metadata rows
fputcsv($out, ['Section filter:', $section_filter !== '' ? $section_filter : '(all)']);
if ($q !== '') fputcsv($out, ['Search:', $q]);
fputcsv($out, ['Sort:', $sort . ' ' . $sdir]);
fputcsv($out, ['Date Generated:', date('Y-m-d H:i:s')]);
fputcsv($out, ['Total:', count($rows)]);
fputcsv($out, []); // blank row

// Data rows
fputcsv($out, ['Fullname', 'Gender', 'Section', 'Username']);
foreach ($rows as $r) {
    fputcsv($out, [$r['fullname'], $r['gender'], $r['section_name'] ?? '—', $r['username']]);
}

fclose($out);
exit;
