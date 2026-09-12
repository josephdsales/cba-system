<?php
// Direct PDF download of a student's submitted exam review.
// Correct answers are NEVER included (not queried, not in the file).
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/pdf.php';
$user = require_role('student');

$attempt_id = (int)($_GET['attempt_id'] ?? 0);
$st = db()->prepare("SELECT a.*, e.title FROM attempts a
    JOIN exams e ON e.id=a.exam_id
    WHERE a.id=? AND a.student_id=? AND a.submitted_at IS NOT NULL");
$st->execute([$attempt_id, $user['id']]);
$attempt = $st->fetch();
if (!$attempt) { http_response_code(404); exit('Review not available.'); }

$st = db()->prepare("SELECT q.id, q.question_text, q.qtype, q.option_a, q.option_b,
        q.option_c, q.option_d, q.points, an.student_answer, an.is_correct, an.points_earned
    FROM questions q LEFT JOIN answers an ON an.question_id=q.id AND an.attempt_id=?
    WHERE q.exam_id=? ORDER BY q.sort_order, q.id");
$st->execute([$attempt_id, $attempt['exam_id']]);
$items = $st->fetchAll();

$pdf = new MiniPDF();
$pdf->addLine($attempt['title'], 16, true);
$pdf->addLine('Examination Result', 11);
$pdf->blank();
$pdf->addLine('Student: ' . $user['fullname']);
$pdf->addLine('Date taken: ' . date('m-d-Y H:i:s', strtotime($attempt['submitted_at'])));
$pdf->addLine('Score: ' . $attempt['score'] . '/' . $attempt['total'] . ' (' . $attempt['percentage'] . '%)');
$pdf->addLine($attempt['percentage'] >= $attempt['passing_percent'] ? 'PASSED' : 'FAILED', 13, true);
$pdf->blank();

$i = 1;
foreach ($items as $q) {
    $ok = !empty($q['is_correct']);
    $mine = $q['student_answer'] ?? '';
    $pdf->addLine($i . '. ' . $q['question_text'] . ' [' . ($ok ? 'Correct' : 'Wrong') . ']', 11, true, 2);
    if ($q['qtype'] === 'mcq') {
        foreach (['A' => $q['option_a'], 'B' => $q['option_b'], 'C' => $q['option_c'], 'D' => $q['option_d']] as $L => $opt) {
            if ($opt === null || $opt === '') continue;
            $mark = (strtoupper(substr($mine, 0, 1)) === $L) ? ' (your answer)' : '';
            $pdf->addLine('    ' . $L . '. ' . $opt . $mark);
        }
    } else {
        $pdf->addLine('    Your answer: ' . ($mine !== '' && $mine !== null ? $mine : '(blank)'));
    }
    $pdf->addLine('    Points: ' . $q['points_earned'] . '/' . $q['points']);
    $pdf->blank();
    $i++;
}

$bin = $pdf->render();
$fname = 'Result-' . preg_replace('/[^A-Za-z0-9-_]+/', '_', $attempt['title']) . '-' . $attempt_id . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . strlen($bin));
header('Cache-Control: private, must-revalidate');
echo $bin;
exit;
