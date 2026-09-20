<?php
// Teacher download: Item Analysis as Excel (CSV) file
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('teacher');

$exam_id = (int)($_GET['exam_id'] ?? 0);
$st = db()->prepare('SELECT * FROM exams WHERE id=? AND teacher_id=?');
$st->execute([$exam_id, $user['id']]);
$exam = $st->fetch();
if (!$exam) { http_response_code(404); exit('Exam not found.'); }

// Get teacher info
$st = db()->prepare('SELECT fullname FROM users WHERE id=?');
$st->execute([$user['id']]);
$teacher = $st->fetch();

// Determine section info
$section_name = 'All Sections';
if (!empty($exam['section_id'])) {
    $st = db()->prepare('SELECT name FROM sections WHERE id=?');
    $st->execute([$exam['section_id']]);
    $sec = $st->fetch();
    $section_name = $sec['name'] ?? 'All Sections';
} elseif ($best_rows) {
    // Check if all students are from same section
    $sections = array_unique(array_column($best_rows, 'section_name'));
    $sections = array_filter($sections);
    if (count($sections) === 1) {
        $section_name = reset($sections);
    } else {
        $section_name = 'Multiple Sections';
    }
}

// Get best attempt per student for this exam
$st = db()->prepare("SELECT a.*, u.fullname, u.username, u.gender, u.lastname, u.firstname, s.name AS section_name
    FROM attempts a
    JOIN users u ON u.id=a.student_id
    LEFT JOIN sections s ON s.id=u.section_id
    JOIN (
        SELECT student_id, MAX(percentage) AS max_pct
        FROM attempts
        WHERE exam_id=? AND submitted_at IS NOT NULL
        GROUP BY student_id
    ) best ON best.student_id=a.student_id AND best.max_pct=a.percentage
    WHERE a.exam_id=? AND a.submitted_at IS NOT NULL
    ORDER BY a.percentage DESC");
$st->execute([$exam_id, $exam_id]);
$best_rows = $st->fetchAll();

// Get questions with analysis
$st = db()->prepare("SELECT q.id, q.question_text, q.qtype, q.option_a, q.option_b, q.option_c, q.option_d, q.correct_answer, q.points
    FROM questions q
    WHERE q.exam_id=?
    ORDER BY q.sort_order, q.id");
$st->execute([$exam_id]);
$questions = $st->fetchAll();

// Build item analysis
$item_analysis = [];
foreach ($questions as $q) {
    // Get answer stats from best attempts
    $st2 = db()->prepare("SELECT COUNT(an.id) AS tries, COALESCE(SUM(an.is_correct),0) AS got
        FROM answers an
        LEFT JOIN attempts t ON t.id=an.attempt_id AND t.submitted_at IS NOT NULL
        JOIN (
            SELECT student_id, MAX(percentage) AS max_pct
            FROM attempts
            WHERE exam_id=? AND submitted_at IS NOT NULL
            GROUP BY student_id
        ) best ON best.student_id=t.student_id AND best.max_pct=t.percentage
        WHERE an.question_id=? AND t.id IS NOT NULL");
    $st2->execute([$exam_id, $q['id']]);
    $stats = $st2->fetch();
    
    $tries = (int)$stats['tries'];
    $got = (int)$stats['got'];
    $pct = $tries > 0 ? round($got / $tries * 100, 2) : 0;
    
    $item_analysis[] = [
        'id' => $q['id'],
        'text' => $q['question_text'],
        'qtype' => $q['qtype'],
        'got' => $got,
        'tries' => $tries,
        'pct' => $pct,
    ];
}

// CSV output
$filename = 'ItemAnalysis-' . preg_replace('/[^A-Za-z0-9-_]+/', '_', $exam['title']) . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, must-revalidate');

$out = fopen('php://output', 'w');

// UTF-8 BOM for Excel
fwrite($out, "\xEF\xBB\xBF");

// Metadata rows
fputcsv($out, ['Section:', $section_name]);
fputcsv($out, ['Exam Name:', $exam['title']]);
fputcsv($out, ['Teacher Name:', $teacher['fullname'] ?? $user['fullname']]);
fputcsv($out, ['Date Generated:', date('Y-m-d H:i:s')]);
fputcsv($out, ['Total Students (Best Attempt):', count($best_rows)]);
fputcsv($out, []); // blank row

// Table headers
fputcsv($out, [
    'Item Number',
    'Question',
    'Type',
    'Correct',
    'Total',
    'P-value (%)'
]);

// Data rows
$item_num = 1;
foreach ($item_analysis as $ia) {
    fputcsv($out, [
        $item_num++,
        $ia['text'],
        $ia['qtype'],
        $ia['got'],
        $ia['tries'],
        $ia['pct'] . '%'
    ]);
}

fclose($out);
exit;