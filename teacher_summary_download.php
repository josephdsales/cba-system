<?php
// Teacher download: exam summary as a real PDF file
// (title, stats, student list with scores) — no print dialog.
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/pdf.php';
$user = require_role('teacher');

$exam_id = (int)($_GET['exam_id'] ?? 0);
$st = db()->prepare('SELECT * FROM exams WHERE id=? AND teacher_id=?');
$st->execute([$exam_id, $user['id']]);
$exam = $st->fetch();
if (!$exam) { http_response_code(404); exit('Exam not found.'); }

$st = db()->prepare("SELECT a.*, u.fullname, s.name AS section_name
    FROM attempts a JOIN users u ON u.id=a.student_id LEFT JOIN sections s ON s.id=u.section_id
    WHERE a.exam_id=? AND a.submitted_at IS NOT NULL ORDER BY a.percentage DESC, u.fullname");
$st->execute([$exam_id]);
$rows = $st->fetchAll();

$pdf = new MiniPDF();
$pdf->addLine($exam['title'], 16, true);
$pdf->addLine('Summary of scores', 11);
$pdf->addLine('Generated: ' . date('m-d-Y H:i:s'), 11);
$pdf->blank();

if ($rows) {
    $perc = array_column($rows, 'percentage');
    $hi = (float)max($perc); $lo = (float)min($perc);
    $topNames = [];
    $lowNames = [];
    foreach ($rows as $r) {
        if ((float)$r['percentage'] == $hi) $topNames[] = $r['fullname'];
        if ((float)$r['percentage'] == $lo) $lowNames[] = $r['fullname'];
    }
    $pdf->addLine('Takers: ' . count($rows));
    $pdf->addLine('Average: ' . round(array_sum($perc) / count($perc), 2) . '%');
    $pdf->addLine('Highest: ' . $hi . '% (' . implode(', ', $topNames) . ')');
    $pdf->addLine('Lowest: ' . $lo . '% (' . implode(', ', $lowNames) . ')');
    $pdf->blank();
    $i = 1;
    foreach ($rows as $r) {
        $pdf->addLine($i . '. ' . $r['fullname'] . ' (' . ($r['section_name'] ?? '-') . ') - '
            . $r['score'] . '/' . $r['total'] . ' (' . $r['percentage'] . '%) - '
            . date('m-d-Y H:i:s', strtotime($r['submitted_at'])));
        $i++;
    }
} else {
    $pdf->addLine('No submissions yet.');
}

$bin = $pdf->render();
$fname = 'Summary-' . preg_replace('/[^A-Za-z0-9-_]+/', '_', $exam['title']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . strlen($bin));
header('Cache-Control: private, must-revalidate');
echo $bin;
exit;
