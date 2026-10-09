<?php
// Teacher download: Question analysis (ranked by % correct) as Excel (CSV)
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('teacher');

$exam_id = (int)($_GET['exam_id'] ?? 0);
$st = db()->prepare('SELECT * FROM exams WHERE id=? AND teacher_id=?');
$st->execute([$exam_id, $user['id']]);
$exam = $st->fetch();
if (!$exam) { http_response_code(404); exit('Exam not found.'); }

$st = db()->prepare('SELECT COUNT(DISTINCT a.student_id) FROM attempts a WHERE a.exam_id=? AND a.submitted_at IS NOT NULL');
$st->execute([$exam_id]);
$takers = (int)$st->fetchColumn();

// Same query as the Question analysis block on teacher_results.php,
// so the download always matches what the page shows.
$st = db()->prepare("SELECT q.id, q.question_text, q.qtype, q.option_a, q.option_b, q.option_c, q.option_d, q.correct_answer, q.points,
    COUNT(an.id) AS tries, COALESCE(SUM(an.is_correct),0) AS got
    FROM questions q LEFT JOIN answers an ON an.question_id=q.id
    LEFT JOIN attempts t ON t.id=an.attempt_id AND t.submitted_at IS NOT NULL
    JOIN (
        SELECT student_id, MAX(percentage) AS max_pct
        FROM attempts
        WHERE exam_id=? AND submitted_at IS NOT NULL
        GROUP BY student_id
    ) best ON best.student_id=t.student_id AND best.max_pct=t.percentage
    WHERE q.exam_id=? AND (an.id IS NULL OR t.id IS NOT NULL)
    GROUP BY q.id, q.question_text, q.qtype, q.option_a, q.option_b, q.option_c, q.option_d, q.correct_answer, q.points");
$st->execute([$exam_id, $exam_id]);

$analysis = [];
foreach ($st->fetchAll() as $r) {
    $tries = (int)$r['tries'];
    if ($tries > 0) {
        $analysis[] = ['text' => $r['question_text'], 'qtype' => $r['qtype'],
            'got' => (int)$r['got'], 'tries' => $tries,
            'pct' => round($r['got'] / $tries * 100, 1)];
    }
}
usort($analysis, function ($a, $b) { return $b['pct'] <=> $a['pct']; });

$filename = 'QuestionAnalysis-' . preg_replace('/[^A-Za-z0-9-_]+/', '_', $exam['title']) . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, must-revalidate');

$out = fopen('php://output', 'w');

// UTF-8 BOM for Excel
fwrite($out, "\xEF\xBB\xBF");

// Metadata rows
fputcsv($out, ['Exam:', $exam['title']]);
fputcsv($out, ['Teacher:', $user['fullname']]);
fputcsv($out, ['Students:', $takers]);
fputcsv($out, ['Date Generated:', date('Y-m-d H:i:s')]);
fputcsv($out, []); // blank row

// Table headers
fputcsv($out, ['Rank (Easiest First)', 'Question', 'Type', 'Correct', 'Total', '% Correct']);

// Data rows, easiest to hardest
$rank = 1;
foreach ($analysis as $a) {
    fputcsv($out, [$rank++, $a['text'], $a['qtype'], $a['got'], $a['tries'], $a['pct'] . '%']);
}

fclose($out);
exit;
