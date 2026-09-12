<?php
// Teacher download: one student's review as a PDF file (includes the answer key).
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/pdf.php';
$user = require_role('teacher');

$attempt_id = (int)($_GET['attempt_id'] ?? 0);
$st = db()->prepare("SELECT a.*, e.title, e.passing_percent, u.fullname AS student_name
    FROM attempts a JOIN exams e ON e.id=a.exam_id JOIN users u ON u.id=a.student_id
    WHERE a.id=? AND e.teacher_id=? AND a.submitted_at IS NOT NULL");
$st->execute([$attempt_id, $user['id']]);
$attempt = $st->fetch();
if (!$attempt) { http_response_code(404); exit('Review not available.'); }

$st = db()->prepare("SELECT q.*, an.student_answer, an.is_correct, an.points_earned
    FROM questions q LEFT JOIN answers an ON an.question_id=q.id AND an.attempt_id=?
    WHERE q.exam_id=? ORDER BY q.sort_order, q.id");
$st->execute([$attempt_id, $attempt['exam_id']]);
$items = $st->fetchAll();

$pdf = new MiniPDF();
$pdf->setFooter('DOÑA JUANA CHIOCO NATIONAL HIGH SCHOOL - Computer-based Assessment - JDS');
$pdf->addLine($attempt['title'], 16, true);
$pdf->addLine('Student review (with answer key)', 11);
$pdf->blank();
$pdf->addLine('Student: ' . $attempt['student_name']);
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
            $tags = '';
            if (strtoupper($q['correct_answer']) === $L) $tags .= ' [correct answer]';
            if (strtoupper(substr($mine, 0, 1)) === $L) $tags .= " (student's answer)";
            $pdf->addLine('    ' . $L . '. ' . $opt . $tags);
        }
    } else {
        $pdf->addLine('    Correct answer: ' . $q['correct_answer']);
        $pdf->addLine("    Student's answer: " . ($mine !== '' && $mine !== null ? $mine : '(blank)'));
    }
    $pdf->addLine('    Points: ' . $q['points_earned'] . '/' . $q['points']);
    $pdf->blank();
    $i++;
}

$bin = $pdf->render();
$fname = 'Review-' . preg_replace('/[^A-Za-z0-9-_]+/', '_', $attempt['title']) . '-' . preg_replace('/[^A-Za-z0-9-_]+/', '_', $attempt['student_name']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . strlen($bin));
header('Cache-Control: private, must-revalidate');
echo $bin;
exit;
