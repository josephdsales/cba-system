<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/schema.php';
$user = require_role('teacher');

$exam_id = (int)($_GET['exam_id'] ?? 0);
$st = db()->prepare('SELECT * FROM exams WHERE id=? AND teacher_id=?');
$st->execute([$exam_id, $user['id']]);
$exam = $st->fetch();
if (!$exam) { header('Location: teacher_exams.php'); exit; }

// Ensure the questions table has steps_data + the 'arrangingsteps' qtype
// (older deployments created the table before these existed).
ensure_arrangingsteps();

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

// Parse plain-text / docx exam format into questions.
// Supports paper-style tests with section headers (Multiple Choice, True or False,
// Identification, Arranging Steps), two-column options, inline ID items, and optional answers.
function parse_exam_text(string $text): array {
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), function ($l) { return $l !== ''; }));
    
    $out = [];
    $curSection = null;

    $LETTERS = ['a', 'b', 'c', 'd'];
    $LIDX = ['a' => 0, 'b' => 1, 'c' => 2, 'd' => 3];

    $stripQ = function(string $ln): string {
        return preg_replace('/^\s*(Q\s*\d*|Question\s*\d+|\d+)\s*[.):\-]\s*/i', '', $ln);
    };
    $stripPrefix = function(string $ln): string {
        return trim(preg_replace('/^[_\-\s]+/', '', $ln));
    };
    $stripQnum = function(string $ln): string {
        return trim(preg_replace('/^\s*\d+\s*[.):\-]\s*/', '', $ln));
    };
    // A question line starts with "1.", "Q1.", "Question 1.", etc.
    // Range headers like "Q18-Q25." introduce a block of items, not a question.
    $isQStart = function(string $ln): bool {
        if (preg_match('/^Q\s*\d+\s*[-–—]\s*\d+/i', $ln)) return false;
        return (bool)preg_match('/^\s*(?:Q\s*\d+|Question\s*\d+|\d+)\s*[.):\-]\s*\S/i', $ln);
    };
    // Decide qtype + default answer once the choices/answer are known.
    $finalize = function(array $q, bool $hasOpts, ?string $curSection): array {
        if ($hasOpts) {
            $q['qtype'] = 'mcq';
            if ($q['answer'] === '') $q['answer'] = 'A';
        } elseif ($curSection === 'truefalse' || preg_match('/^(true|false|t|f)\b/i', $q['answer'])) {
            $q['qtype'] = 'truefalse';
            $q['answer'] = in_array(strtolower($q['answer']), ['false', 'f'], true) ? 'False' : 'True';
        } else {
            // Identification. An empty '?' answer lets the teacher type the
            // correct answer later; any answer found in the file is kept.
            $q['qtype'] = 'identification';
            if ($q['answer'] === '') $q['answer'] = '?';
        }
        return $q;
    };
    $isHeaderJunk = function(string $ln): bool {
        if (preg_match('/^name\s*:/i', $ln)) return true;
        if (preg_match('/^grade\s+and\s+section/i', $ln)) return true;
        if (preg_match('/^date\s*:/i', $ln)) return true;
        if (preg_match('/^score\s*:/i', $ln)) return true;
        if (preg_match('/^(tle\s*\d+|summative|test|quiz|examination|periodical|quarterly|midterm|final|pretest|posttest)\b/i', $ln)) return true;
        if (preg_match('/^(i{1,3}v?|iv|v?i{0,3})\s*[.)]\s*\S/i', $ln) && strlen($ln) < 120) return true;
        if (preg_match('/^write\s+[ts]\s+if|write\s+s\s+for|choose\s+the\s+letter/i', $ln)) return true;
        if (preg_match('/^for\s+items\s+\d+\s*-\s*\d+\s*\./i', $ln)) return true;
        return false;
    };
    $isSectionHeader = function(string $ln): ?string {
        if (preg_match('/multiple\s+choice/i', $ln)) return 'mcq';
        if (preg_match('/true\s+or\s+false|true\s*\/\s*false/i', $ln)) return 'truefalse';
        if (preg_match('/identification/i', $ln)) return 'identification';
        if (preg_match('/arrange|arranging|chronological/i', $ln)) return 'arrangingsteps';
        if (preg_match('/essay/i', $ln)) return 'essay';
        return null;
    };
    $splitOptionParts = function(string $ln): array {
        return preg_split('/\s+(?=[A-D][.):])/i', $ln);
    };
    $assignOptions = function(array &$q, array $parts) use ($LETTERS, $LIDX) {
        $firstFree = function() use (&$q, $LETTERS) {
            for ($i = 0; $i < 4; $i++) if ($q[$LETTERS[$i]] === null) return $i;
            return -1;
        };
        foreach ($parts as $part) {
            if (preg_match('/^\(?([A-D])[.):]\s*(.+)$/i', $part, $m)) {
                $idx = $LIDX[strtolower($m[1])] ?? null;
                if ($idx !== null && $q[$LETTERS[$idx]] === null) $q[$LETTERS[$idx]] = trim($m[2]);
            } else {
                $idx = $firstFree();
                if ($idx >= 0 && $q[$LETTERS[$idx]] === null) $q[$LETTERS[$idx]] = trim($part);
            }
        }
    };
    $extractSteps = function(array $linesArr): array {
        $steps = [];
        foreach ($linesArr as $ln) {
            if (preg_match('/^step\s*(\d+)\s*[:.\-]\s*(.+)$/i', $ln, $s)) {
                $steps[] = ['n' => (int)$s[1], 'text' => trim($s[2])];
            }
        }
        return $steps;
    };
    $extractInlineIdent = function(string $lnText): array {
        $items = [];
        if (preg_match_all('/(?:_{3,}|\b)\s*(\d+)\s*[.):\-]\s*([A-Za-z0-9][^_]*?)(?=\s+(?:_{3,}|\b\d+\s*[.):\-])|\s{3,}_|$)/u', $lnText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $txt = trim(preg_replace('/^[_\-\s]+/', '', $m[2]));
                if ($txt !== '') $items[] = ['n' => (int)$m[1], 'text' => $txt];
            }
        }
        return $items;
    };

    for ($i = 0; $i < count($lines); $i++) {
        $ln = $lines[$i];
        $sec = $isSectionHeader($ln);
        if ($sec) { $curSection = $sec; continue; }
        if ($isHeaderJunk($ln)) continue;

        // 1. Arranging Steps
        if (preg_match('/^step\s*\d+\s*[:.\-]/i', $ln) || $curSection === 'arrangingsteps') {
            if (preg_match('/^step\s*\d+\s*[:.\-]/i', $ln)) {
                $stepLines = [$ln];
                while ($i + 1 < count($lines) && preg_match('/^step\s*\d+\s*[:.\-]/i', $lines[$i + 1])) {
                    $i++;
                    $stepLines[] = $lines[$i];
                }
                $stepObjs = $extractSteps($stepLines);
                if (!empty($stepObjs)) {
                    $order = [];
                    $stepsData = [];
                    foreach ($stepObjs as $so) {
                        $order[] = (string)$so['n'];
                        $stepsData[] = ['step' => $so['n'], 'text' => $so['text']];
                    }
                    $out[] = [
                        'qtype' => 'arrangingsteps',
                        'text' => 'Arrange the following steps in correct order',
                        'steps_data' => json_encode($stepsData),
                        'answer' => implode('-', $order),
                        'points' => count($stepObjs),
                        'a' => null, 'b' => null, 'c' => null, 'd' => null
                    ];
                    continue;
                }
            } elseif (preg_match('/^[_\-\s]{3,}/', $ln) && $curSection === 'arrangingsteps') {
                $stepLines = [];
                $j = $i;
                while ($j < count($lines) && preg_match('/^[_\-\s]{3,}/', $lines[$j]) && !preg_match('/^[_\-\s]{3,}\s*\d+\s*[.):\-]/', $lines[$j])) {
                    $stepLines[] = $lines[$j];
                    $j++;
                }
                if (count($stepLines) >= 2) {
                    $stepsData = [];
                    $order = [];
                    foreach ($stepLines as $idx => $sl) {
                        $stepNum = $idx + 1;
                        $stepsData[] = ['step' => $stepNum, 'text' => $stripPrefix($sl)];
                        $order[] = (string)$stepNum;
                    }
                    $out[] = [
                        'qtype' => 'arrangingsteps',
                        'text' => 'Arrange the following steps in correct order',
                        'steps_data' => json_encode($stepsData),
                        'answer' => implode('-', $order),
                        'points' => count($stepsData),
                        'a' => null, 'b' => null, 'c' => null, 'd' => null
                    ];
                    $i = $j - 1;
                    continue;
                }
            }
        }

        // 2. Identification: inline multi-item
        if ($curSection === 'identification' && (strpos($ln, '___') !== false || preg_match('/\d+\s*[.):\-]/', $ln))) {
            $items = $extractInlineIdent($ln);
            if (!empty($items)) {
                foreach ($items as $it) {
                    $out[] = [
                        'qtype' => 'identification',
                        'text' => $it['text'],
                        'steps_data' => null,
                        'correct_answer' => '?',
                        'points' => 1,
                        'a' => null, 'b' => null, 'c' => null, 'd' => null,
                        'answer' => '?'
                    ];
                }
                continue;
            }
        }

        // 3. Standard numbered question (MCQ, True/False, or Identification)
        if ($isQStart($ln)) {
            // Lines like "Q5. Arrange the following steps..." only introduce an
            // Arranging-Steps item — skip them and let the Step N. lines below
            // build the question (otherwise a duplicate junk question is made).
            $nxt = $lines[$i + 1] ?? '';
            if ($isSectionHeader($nxt) === 'arrangingsteps' || preg_match('/^step\s*\d+\s*[:.\-]/i', $nxt)) {
                continue;
            }
            $cleanedLine = $stripPrefix($ln);
            $qtext = $stripQ($cleanedLine);
            $qlines = [$qtext];
            
            $lookahead = [];
            $idx = $i;
            while ($idx + 1 < count($lines) && !$isQStart($lines[$idx + 1]) && !$isSectionHeader($lines[$idx + 1])) {
                $idx++;
                $lookahead[] = $lines[$idx];
            }

            $q = [
                'qtype' => 'mcq',
                'text' => $qtext,
                'a' => null, 'b' => null, 'c' => null, 'd' => null,
                'steps_data' => null,
                'correct_answer' => '',
                'points' => 1,
                'answer' => ''
            ];

            $hasOpts = false;
            foreach ($lookahead as $la) {
                if (preg_match('/^[A-D][.):]/i', $la)) {
                    $parts = $splitOptionParts($la);
                    $assignOptions($q, $parts);
                    $hasOpts = true;
                    $i++; // consume line
                } elseif (preg_match('/^(answer|ans|correct\s*answer)\s*(?:key)?\s*[:.\-]\s*(.+)$/i', $la, $am)) {
                    $q['answer'] = trim($am[2]);
                    $i++; // consume line
                }
            }

            $q = $finalize($q, $hasOpts, $curSection);
            $out[] = $q;
            continue;
        }

        // 4. Word auto-numbered questions: the numbering lives in Word's list
        // formatting, so the text has no "1." / "Q1." prefix. A plain line only
        // counts as a question when choices or an Answer line follow it.
        if (!preg_match('/^(answer|ans|correct\s*answer)\s*(?:key)?\s*[:.\-]/i', $ln)
            && !preg_match('/^points?\s*[:\-]/i', $ln)) {
            $group = [$ln];
            $idx = $i;
            while ($idx + 1 < count($lines)
                   && !$isQStart($lines[$idx + 1])
                   && !$isSectionHeader($lines[$idx + 1])
                   && !preg_match('/^step\s*\d+\s*[:.\-]/i', $lines[$idx + 1])) {
                $idx++;
                $group[] = $lines[$idx];
            }
            $textParts = [];
            $hasOpts = false;
            $answer = '';
            $lastConsumed = $i;
            foreach ($group as $gi => $gl) {
                if (preg_match('/^(answer|ans|correct\s*answer)\s*(?:key)?\s*[:.\-]\s*(.+)$/i', $gl, $am)) {
                    $answer = trim($am[2]);
                    $lastConsumed = $i + $gi;
                    break; // the answer line completes the question
                }
                if (preg_match('/^[A-D][.):]/i', $gl)) {
                    $hasOpts = true;
                    $lastConsumed = $i + $gi;
                    continue;
                }
                if ($hasOpts) break; // a bare line after choices = next question
                $textParts[] = $gl;  // wrapped text (soft line break) joins in
                $lastConsumed = $i + $gi;
            }
            if ($hasOpts || $answer !== '') {
                $q = [
                    'qtype' => 'mcq',
                    'text' => trim(implode(' ', $textParts)),
                    'a' => null, 'b' => null, 'c' => null, 'd' => null,
                    'steps_data' => null,
                    'correct_answer' => '',
                    'points' => 1,
                    'answer' => $answer
                ];
                if ($hasOpts) {
                    foreach ($group as $gl) {
                        if (preg_match('/^[A-D][.):]/i', $gl)) $assignOptions($q, $splitOptionParts($gl));
                    }
                }
                $q = $finalize($q, $hasOpts, $curSection);
                $i = $lastConsumed;
                $out[] = $q;
                continue;
            }
        }

        // Answer: B (standalone)
        $ansMatch = [];
        if (preg_match('/^(answer|ans|correct\s*answer)\s*(?:key)?\s*[:.\-]\s*(.+)$/i', $ln, $ansMatch)) {
            $ans = trim($ansMatch[2]);
            if (!empty($out)) {
                $last = &$out[count($out) - 1];
                $last['answer'] = $ans;
                if ($last['qtype'] === 'mcq') {
                    $clean = preg_replace('/[^A-Za-z]/', '', $ans);
                    $last['answer'] = strtoupper(substr($clean, 0, 1));
                } elseif ($last['qtype'] === 'truefalse') {
                    $last['answer'] = in_array(strtolower($ans), ['false', 'f'], true) ? 'False' : 'True';
                }
            }
            continue;
        }

        // Points: 2
        $ptsMatch = [];
        if (preg_match('/^points?\s*[:\-]\s*(\d+)/i', $ln, $ptsMatch)) {
            if (!empty($out)) {
                $out[count($out) - 1]['points'] = max(1, (int)$ptsMatch[1]);
            }
            continue;
        }
    }

    // Answer key block at end: "Answers: 1.B 2.A 3.C"
    if (preg_match('/answers?\s*:\s*(.+)/i', $text, $keyMatch)) {
        $entries = preg_split('/\s+/', $keyMatch[1]);
        foreach ($entries as $e) {
            if (preg_match('/^(\d+)\s*[.):\-]?\s*([A-Za-z])/i', $e, $m)) {
                $idx = (int)$m[1] - 1;
                if (isset($out[$idx])) {
                    $out[$idx]['answer'] = strtoupper($m[2]);
                }
            }
        }
    }

    $finalOut = [];
    foreach ($out as $q) {
        if ($q['qtype'] === 'mcq') {
            $q['answer'] = $q['answer'] !== '' ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $q['answer']), 0, 1)) : 'A';
        } elseif ($q['qtype'] === 'truefalse') {
            $q['answer'] = in_array(strtolower($q['answer']), ['false', 'f', 'no'], true) ? 'False' : 'True';
        } else {
            $q['answer'] = $q['answer'] !== '' ? $q['answer'] : '?';
        }
        $finalOut[] = $q;
    }
    return $finalOut;
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
        $ins = db()->prepare('INSERT INTO questions (exam_id, question_text, qtype, option_a, option_b, option_c, option_d, steps_data, correct_answer, points, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($qs as $q) {
            $n++;
            $ins->execute([$exam_id, $q['text'], $q['qtype'], $q['a'] ?? null, $q['b'] ?? null, $q['c'] ?? null, $q['d'] ?? null, $q['steps_data'] ?? null, $q['answer'], $q['points'], $n]);
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
Type: True/False
Answer: False
Points: 1

Q3. Who is the national hero of the Philippines?
Answer: Jose Rizal
Points: 2

Q4. Arrange the following steps in the correct order.
Type: Arranging Steps
Step 1. Open the box
Step 2. Remove the manual
Step 3. Plug in the power cord</pre>
  <p class="hint">Tips: one question per block, blank line between (also works without blank lines if each question starts with Q1., 1., etc.). Options as <code class="inline">A. ...</code> (also <code class="inline">A) ...</code>; several options on one line are split automatically). Answer line as <code class="inline">Answer: B</code> (also <code class="inline">Ans:</code> / <code class="inline">Correct answer:</code> / <code class="inline">Answer Key:</code>) / <code class="inline">True</code> / text. Word auto-numbered lists work too. <b>Arranging steps</b>: add <code class="inline">Type: Arranging Steps</code> and list the steps <b>in the correct order</b> as <code class="inline">Step 1. ...</code> (plain numbered lines like <code class="inline">1. ...</code> also work; a range header like <code class="inline">Q18-Q25.</code> is fine) — the system shuffles them for students, and the item is worth one point per step (partial credit per correctly placed step). Save as <code class="inline">.docx</code> then upload. If it fails, the error shows the text read from your file — compare it with the format above.</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
