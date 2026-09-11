<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('teacher');

$exam_id = (int)($_GET['exam_id'] ?? 0);
$st = db()->prepare('SELECT * FROM exams WHERE id=? AND teacher_id=?');
$st->execute([$exam_id, $user['id']]);
$exam = $st->fetch();
if (!$exam) { header('Location: teacher_exams.php'); exit; }

// ---------- helpers ----------
function docx_to_text(string $path): string {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Cannot open .docx file.');
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!$xml) throw new RuntimeException('Invalid .docx: word/document.xml missing.');
    // paragraphs AND table cells/rows -> newlines; soft line-breaks -> newlines too
    $xml = preg_replace('/<\/(w:p|w:tc|w:tr)[^>]*>/i', "\n", $xml);
    $xml = preg_replace('/<w:br[^>]*\/>/i', "\n", $xml);
    $xml = preg_replace('/<w:tab[^>]*\/>/i', ' ', $xml);
    $text = strip_tags($xml);
    // collapse 3+ newlines (keeps blank-line separators intact)
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// Parse plain-text exam format into questions.
// Block format (blank line separates questions):
//   Q1. What is 2+2?
//   A. 3   B. 4   C. 5   D. 6
//   Answer: B
//   Points: 1
// True/False:  "Q. Manila is the capital.  Answer: True"
// Identification: "Q. What is ...?  Answer: Jose Rizal" (no A-D lines)
function parse_exam_text(string $text): array {
    $qs = parse_blocks($text);
    if ($qs) return $qs;
    // Fallback: whole exam pasted on one line (no line breaks in the file).
    // Segment the inline markers (options, Answer, Points, Q-numbers), then re-parse.
    $seg = $text;
    $seg = preg_replace('/\s+(Answer|Ans|Correct answer)s?\s*:\s*/i', "\nAnswer: ", $seg);
    $seg = preg_replace('/\s+Points?\s*:\s*/i', "\nPoints: ", $seg);
    $seg = preg_replace('/\s+(\d+)\s+points?(?=\s|$)/i', "\nPoints: $1", $seg);
    $seg = preg_replace('/\s+([A-D])[\.\)]\s+(?=\S)/', "\n$1. ", $seg);
    $seg = preg_replace('/\s+(Q\s*\d+)\s*[\.\)\:]\s*/i', "\n$1. ", $seg);
    return parse_blocks($seg);
}

function parse_blocks(string $text): array {
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    $raw_blocks = preg_split("/\n\s*\n/", $text);
    // Re-split: a new question may start WITHOUT a blank line before it.
    $blocks = [];
    foreach ($raw_blocks as $b) {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $b)), function ($l) { return $l !== ''; }));
        $cur = []; $has_answer = false;
        foreach ($lines as $ln) {
            $is_answer = (bool)preg_match('/^(answer|ans|correct\s*answer)s?\s*[:\-\.]?\s*.+/i', $ln);
            $is_qstart = (bool)preg_match('/^(Q\s*\d*|Question\s*\d+|\d+)\s*[\.\)\:\-]\s*\S/i', $ln);
            if ($is_qstart && $has_answer && $cur) { $blocks[] = $cur; $cur = []; $has_answer = false; }
            $cur[] = $ln;
            if ($is_answer) $has_answer = true;
        }
        if ($cur) $blocks[] = $cur;
    }
    $out = [];
    foreach ($blocks as $lines) {
        $q = ['text' => '', 'a' => null, 'b' => null, 'c' => null, 'd' => null,
              'answer' => '', 'points' => 1, 'qtype' => 'mcq'];
        $qlines = [];
        foreach ($lines as $ln) {
            if (preg_match('/^\(?([A-Da-d])[\)\.\:]\s*(.+)$/', $ln, $m) && !preg_match('/^answer/i', $ln)) {
                $q[strtolower($m[1])] = trim($m[2]);
            } elseif (preg_match('/^(answer|ans|correct\s*answer)s?\s*[:\-\.]?\s*(.+)$/i', $ln, $m)) {
                $q['answer'] = trim($m[2]);
            } elseif (preg_match('/^points?\s*[:\-]?\s*(\d+)/i', $ln, $m)
                   || preg_match('/^\(?(\d+)\s*points?\)?$/i', $ln, $m)) {
                $q['points'] = max(1, (int)$m[1]);
            } elseif (preg_match('/^(type|qtype)\s*[:\-]\s*(.+)$/i', $ln, $m)) {
                $t = strtolower(trim($m[2]));
                if (strpos($t, 'true') !== false) $q['qtype'] = 'truefalse';
                elseif (strpos($t, 'ident') !== false) $q['qtype'] = 'identification';
            } else {
                // strip leading "Q1.", "1.", "Q:", "Question 1:" etc.
                $qlines[] = preg_replace('/^(Q\s*\d*|Question\s*\d+|\d+)\s*[\.\)\:\-]\s*/i', '', $ln);
            }
        }
        // Fallback for Word auto-numbered lists (numbers live in Word,
        // not in the text): answer is a letter + trailing bare lines = options.
        $hasOpts = $q['a'] !== null || $q['b'] !== null;
        if (!$hasOpts && preg_match('/^[A-D]$/i', $q['answer']) && count($qlines) >= 3) {
            $take = min(4, count($qlines) - 1);
            $opts = array_splice($qlines, -$take);
            foreach (['a', 'b', 'c', 'd'] as $i => $k) {
                if (isset($opts[$i])) $q[$k] = $opts[$i];
            }
            $hasOpts = true;
        }
        $q['text'] = implode(' ', $qlines);
        if ($q['text'] === '' || $q['answer'] === '') continue; // skip incomplete
        // auto-detect type when not explicit
        $ans = strtolower($q['answer']);
        if (!$hasOpts && in_array($ans, ['true', 'false'], true)) $q['qtype'] = 'truefalse';
        elseif (!$hasOpts) $q['qtype'] = 'identification';
        if ($q['qtype'] === 'mcq') $q['answer'] = strtoupper(substr($q['answer'], 0, 1));
        $out[] = $q;
    }
    return $out;
}

$imported = 0; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        if (empty($_FILES['examfile']) || $_FILES['examfile']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Please choose a .docx or .txt file.');
        }
        $ext = strtolower(pathinfo($_FILES['examfile']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['docx', 'txt'], true)) throw new RuntimeException('Only .docx or .txt files are accepted.');
        $raw = $ext === 'docx' ? docx_to_text($_FILES['examfile']['tmp_name'])
                               : file_get_contents($_FILES['examfile']['tmp_name']);
        $qs = parse_exam_text($raw);
        if (!$qs) {
            $preview = trim(preg_replace('/\s+/', ' ', substr($raw, 0, 300)));
            throw new RuntimeException('No valid questions found. Follow the sample format below (need "Answer:" line per question). Text read from your file starts with: "' . $preview . '"');
        }
        $st = db()->prepare('SELECT COALESCE(MAX(sort_order),0) m FROM questions WHERE exam_id=?');
        $st->execute([$exam_id]); $n = (int)$st->fetch()['m'];
        $ins = db()->prepare('INSERT INTO questions (exam_id, question_text, qtype, option_a, option_b, option_c, option_d, correct_answer, points, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($qs as $q) {
            $n++;
            $ins->execute([$exam_id, $q['text'], $q['qtype'], $q['a'], $q['b'], $q['c'], $q['d'], $q['answer'], $q['points'], $n]);
            $imported++;
        }
        set_flash("Imported $imported question(s) into '{$exam['title']}'.");
        header("Location: teacher_questions.php?exam_id=$exam_id"); exit;
    } catch (Throwable $ex) { $error = $ex->getMessage(); }
}

$title = 'Import from Word';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <p class="hint">Exam: <b><?= e($exam['title']) ?></b> · Upload a Word <code class="inline">.docx</code> (or plain <code class="inline">.txt</code>) file. Blank line separates questions.</p>
  <?php if ($error): ?><p style="color:#b91c1c"><b><?= e($error) ?></b></p><?php endif; ?>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label>Word / text file</label>
    <input type="file" name="examfile" accept=".docx,.txt" required>
    <div class="btnrow"><button class="btn" type="submit">Import questions</button>
    <a class="btn ghost" href="teacher_questions.php?exam_id=<?= $exam_id ?>">Back</a></div>
  </form>
</div>
<div class="card">
  <h3 style="margin-top:0">Required file format</h3>
  <pre class="sample">Q1. What is the capital of the Philippines?
A. Cebu
B. Manila
C. Davao
D. Baguio
Answer: B
Points: 1

Q2. The Earth is flat.
Answer: False
Points: 1

Q3. Who is the national hero of the Philippines?
Answer: Jose Rizal
Points: 2</pre>
  <p class="hint">Tips: one question per block, blank line between (also works without blank lines if each question starts with Q1., 1., etc.). Options as <code class="inline">A. ...</code> (also <code class="inline">A) ...</code>). Answer line as <code class="inline">Answer: B</code> (also <code class="inline">Ans:</code> / <code class="inline">Correct answer:</code>) / <code class="inline">True</code> / text. Word auto-numbered lists work too. Save as <code class="inline">.docx</code> then upload. If it fails, the error shows the text read from your file — compare it with the format above.</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
