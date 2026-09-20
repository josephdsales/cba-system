<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('teacher');

$st = db()->prepare('SELECT * FROM exams WHERE teacher_id=? ORDER BY created_at DESC');
$st->execute([$user['id']]);
$exams = $st->fetchAll();
$sel = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : (int)($exams[0]['id'] ?? 0);
$mine = array_filter($exams, fn($x) => (int)$x['id'] === $sel);
if ($sel && !$mine) { http_response_code(403); exit('Forbidden'); }

$rows = []; $best_rows = []; $summary = null;
if ($sel) {
    // ALL attempts for table display (teacher sees original + retake)
    $st = db()->prepare("SELECT a.*, u.fullname, u.username, u.gender, u.lastname, u.firstname, s.name AS section_name
        FROM attempts a
        JOIN users u ON u.id=a.student_id
        LEFT JOIN sections s ON s.id=u.section_id
        WHERE a.exam_id=? AND a.submitted_at IS NOT NULL
        ORDER BY a.submitted_at DESC");
    $st->execute([$sel]); $rows = $st->fetchAll();

    // Best attempt per student for summary/analysis (highest percentage)
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
    $st->execute([$sel, $sel]); $best_rows = $st->fetchAll();
    if ($best_rows) {
        $perc = array_column($best_rows, 'percentage');
        $summary = ['takers' => count($best_rows), 'average' => round(array_sum($perc) / count($perc), 2),
            'highest' => max($perc), 'lowest' => min($perc),
            'top' => implode(', ', array_column(array_filter($best_rows, function ($r) use ($perc) { return (float)$r['percentage'] == (float)max($perc); }), 'fullname')),
            'low' => implode(', ', array_column(array_filter($best_rows, function ($r) use ($perc) { return (float)$r['percentage'] == (float)min($perc); }), 'fullname'))];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_attempt') {
    check_csrf();
    $attempt_id = (int)($_POST['attempt_id'] ?? 0);
    $st = db()->prepare("SELECT a.* FROM attempts a JOIN exams e ON e.id=a.exam_id WHERE a.id=? AND e.teacher_id=?");
    $st->execute([$attempt_id, $user['id']]);
    $attempt = $st->fetch();
    if ($attempt) {
        db()->prepare('DELETE FROM answers WHERE attempt_id=?')->execute([$attempt_id]);
        db()->prepare('DELETE FROM attempts WHERE id=?')->execute([$attempt_id]);
        set_flash('Attempt deleted. Student can retake.');
    } else {
        set_flash('Attempt not found.');
    }
    header('Location: teacher_results.php?exam_id=' . $sel); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remedial') {
    check_csrf();
    $mode = $_POST['remedial_mode'] ?? '';
    $target_exam_id = (int)($_POST['target_exam_id'] ?? 0);
    $student_ids = array_map('intval', $_POST['student_ids'] ?? []);
    $shuffle = !empty($_POST['shuffle_questions']);
    $allow_retake = !empty($_POST['allow_retake']);

    // Filter student_ids to only include existing users (prevent FK violation)
    if ($student_ids) {
        $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
        $st = db()->prepare("SELECT id FROM users WHERE id IN ($placeholders)");
        $st->execute($student_ids);
        $valid_students = array_column($st->fetchAll(), 'id');
    } else {
        $valid_students = [];
    }

    // Helper: insert ignore for MySQL / ON CONFLICT DO NOTHING for PostgreSQL
    $assign_students = function ($exam_id, $student_ids) {
        if (db_driver() === 'pgsql') {
            $st = db()->prepare('INSERT INTO exam_students (exam_id, student_id) VALUES (?, ?) ON CONFLICT DO NOTHING');
        } else {
            $st = db()->prepare('INSERT IGNORE INTO exam_students (exam_id, student_id) VALUES (?, ?)');
        }
        foreach ($student_ids as $sid) { $st->execute([$exam_id, $sid]); }
    };

    if ($mode === 'existing' && $target_exam_id && $valid_students) {
        $st = db()->prepare('SELECT * FROM exams WHERE id=? AND teacher_id=?');
        $st->execute([$target_exam_id, $user['id']]);
        $target_exam = $st->fetch();
        if (!$target_exam) { set_flash('Target exam not found.'); }
        else {
            if ($shuffle || $allow_retake) {
                db()->prepare("UPDATE exams SET shuffle_questions=?, allow_retake=?, status='published' WHERE id=?")
                    ->execute([$shuffle ? 1 : 0, $allow_retake ? 1 : 0, $target_exam_id]);
            } else {
                db()->prepare("UPDATE exams SET status='published' WHERE id=?")
                    ->execute([$target_exam_id]);
            }
            $assign_students($target_exam_id, $valid_students);
            set_flash('Assigned ' . count($valid_students) . ' student(s) to "' . $target_exam['title'] . '".' . ($shuffle ? ' Shuffle enabled.' : '') . ($allow_retake ? ' Retake allowed.' : ''));
        }
    } elseif ($mode === 'new' && $valid_students) {
        $title = trim($_POST['new_title'] ?? '');
        $desc = trim($_POST['new_description'] ?? '');
        $time_limit = max(1, (int)($_POST['new_time_limit'] ?? 60));
        $passing = min(100, max(0, (float)($_POST['new_passing'] ?? 50)));
        if ($title === '') { set_flash('New exam title is required.'); }
        else {
            db()->beginTransaction();
            try {
                $st = db()->prepare('INSERT INTO exams (title, description, teacher_id, section_id, time_limit_minutes, passing_percent, status, shuffle_questions, allow_retake) VALUES (?, ?, ?, NULL, ?, ?, "published", ?, ?)');
                $st->execute([$title, $desc ?: null, $user['id'], $time_limit, $passing, $shuffle ? 1 : 0, $allow_retake ? 1 : 0]);
                $new_exam_id = (int)db()->lastInsertId();

                $st = db()->prepare('SELECT * FROM questions WHERE exam_id=? ORDER BY sort_order, id');
                $st->execute([$sel]); $src_questions = $st->fetchAll();
                $ins = db()->prepare('INSERT INTO questions (exam_id, question_text, qtype, option_a, option_b, option_c, option_d, correct_answer, points, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                foreach ($src_questions as $q) {
                    $ins->execute([$new_exam_id, $q['question_text'], $q['qtype'], $q['option_a'], $q['option_b'], $q['option_c'], $q['option_d'], $q['correct_answer'], $q['points'], $q['sort_order']]);
                }

                $assign_students($new_exam_id, $valid_students);

                db()->commit();
                set_flash('Created remedial exam "' . $title . '" with ' . count($src_questions) . ' questions, assigned to ' . count($valid_students) . ' student(s).' . ($shuffle ? ' Shuffle enabled.' : '') . ($allow_retake ? ' Retake allowed.' : ''));
            } catch (Throwable $ex) {
                db()->rollBack();
                set_flash('Failed to create remedial exam: ' . $ex->getMessage());
            }
        }
    } else {
        set_flash('Invalid remedial request.');
    }
    header('Location: teacher_results.php?exam_id=' . $sel); exit;
}

$failed_students = [];
if ($sel && $best_rows) {
    $passing = null;
    foreach ($exams as $x) { if ((int)$x['id'] === $sel) { $passing = (float)$x['passing_percent']; break; } }
    if ($passing !== null) {
        $failed_students = array_filter($best_rows, fn($r) => (float)$r['percentage'] < $passing);
    }
}

$other_exams = array_filter($exams, fn($x) => (int)$x['id'] !== $sel);

$title = 'Exam Results';
$mpl = null;
if ($best_rows) {
    $total = (float)$best_rows[0]['total'];
    $cut = round($total * 0.6, 2);
    $grp = function ($list) use ($cut, $total) {
        $reach = 0; $sum = 0;
        foreach ($list as $r) { $sum += (float)$r['score']; if ((float)$r['score'] >= $cut) $reach++; }
        $n = count($list);
        return ['n' => $n, 'reach' => $reach, 'mps' => ($n > 0 && $total > 0) ? round($sum / $n / $total * 100, 2) : null];
    };
    $male = array_values(array_filter($best_rows, function ($r) { return ($r['gender'] ?? '') === 'Male'; }));
    $female = array_values(array_filter($best_rows, function ($r) { return ($r['gender'] ?? '') === 'Female'; }));
    $mpl = ['cut' => $cut, 'total' => $best_rows[0]['total'],
        'all' => $grp($best_rows), 'male' => $grp($male), 'female' => $grp($female)];
}
$topn = isset($_GET['topn']) ? max(1, min(20, (int)$_GET['topn'])) : 5;
$analysis = [];
$item_analysis = [];
if ($sel && $best_rows) {
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
    $st->execute([$sel, $sel]);
    foreach ($st->fetchAll() as $r) {
        $tries = (int)$r['tries'];
        if ($tries > 0) {
            $pct = round($r['got'] / $tries * 100, 1);
            $analysis[] = ['text' => $r['question_text'], 'pct' => $pct, 'got' => $r['got'], 'tries' => $tries];
            
            // Item analysis for each question
            $item = [
                'id' => $r['id'],
                'text' => $r['question_text'],
                'qtype' => $r['qtype'],
                'pct' => $pct,
                'tries' => $tries,
                'got' => $r['got'],
                'points' => $r['points'],
            ];
            
            if ($r['qtype'] === 'mcq') {
                // Distractor analysis
                $st2 = db()->prepare("SELECT an.student_answer, COUNT(*) as cnt,
                    COALESCE(SUM(an.is_correct),0) as correct_cnt
                    FROM answers an
                    LEFT JOIN attempts t ON t.id=an.attempt_id AND t.submitted_at IS NOT NULL
                    JOIN (
                        SELECT student_id, MAX(percentage) AS max_pct
                        FROM attempts
                        WHERE exam_id=? AND submitted_at IS NOT NULL
                        GROUP BY student_id
                    ) best ON best.student_id=t.student_id AND best.max_pct=t.percentage
                    WHERE an.question_id=? AND t.id IS NOT NULL
                    GROUP BY an.student_answer");
                $st2->execute([$sel, $r['id']]);
                $distractors = [];
                foreach ($st2->fetchAll() as $d) {
                    $ans = $d['student_answer'];
                    $distractors[strtoupper(substr($ans, 0, 1))] = [
                        'count' => (int)$d['cnt'],
                        'pct' => round($d['cnt'] / $tries * 100, 1),
                        'is_correct' => (strtoupper(substr($ans, 0, 1)) === strtoupper($r['correct_answer'])),
                    ];
                }
                $item['distractors'] = $distractors;
            }
            $item_analysis[] = $item;
        }
    }
    usort($analysis, function ($a, $b) { return $b['pct'] <=> $a['pct']; });
}
$sort = $_GET['sort'] ?? 'score';
if (!in_array($sort, ['score', 'date', 'name'], true)) $sort = 'score';
$sdir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
if ($rows) {
    usort($rows, function ($a, $b) use ($sort, $sdir) {
        switch ($sort) {
            case 'date': $c = strcmp($a['submitted_at'], $b['submitted_at']); break;
            case 'name':
                $an = ($a['lastname'] ?? '') !== '' ? $a['lastname'] . ' ' . $a['firstname'] : $a['fullname'];
                $bn = ($b['lastname'] ?? '') !== '' ? $b['lastname'] . ' ' . $b['firstname'] : $b['fullname'];
                $c = strcasecmp($an, $bn); break;
            default: $c = (float)$a['percentage'] <=> (float)$b['percentage'];
        }
        return $sdir === 'asc' ? $c : -$c;
    });
}
function sort_link($label, $key) {
    global $sel, $topn, $sort, $sdir;
    $nd = ($sort === $key && $sdir === 'desc') ? 'asc' : 'desc';
    $arrow = $sort === $key ? ($sdir === 'desc' ? ' ▼' : ' ▲') : '';
    return '<a href="teacher_results.php?exam_id=' . $sel . '&topn=' . $topn . '&sort=' . $key . '&dir=' . $nd . '">' . e($label) . $arrow . '</a>';
}
include __DIR__ . '/includes/header.php';
$selTitle = '';
foreach ($exams as $x) { if ((int)$x['id'] === $sel) { $selTitle = $x['title']; break; } }
?>
<div class="card print-only" style="text-align:center">
  <h2 style="margin:0"><?= e($selTitle !== '' ? $selTitle : 'Exam Results') ?></h2>
  <p class="hint" style="margin:4px 0">Summary of scores · generated <?= date('Y-m-d H:i') ?></p>
</div>
<div class="card no-print">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap">
    <select name="exam_id" style="flex:1;min-width:220px">
      <?php foreach ($exams as $x): ?><option value="<?= $x['id'] ?>" <?= $sel === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['title']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn" type="submit">View</button>
    <?php if ($best_rows): ?><a class="btn ghost" href="teacher_summary_download.php?exam_id=<?= $sel ?>">⬇ Download Summary (PDF)</a><?php endif; ?>
    <?php if ($failed_students): ?>
    <button type="button" class="btn ok" onclick="openRemedialModal()">🩺 Remedial</button>
    <?php endif; ?>
    <?php if ($best_rows): ?>
    <button type="button" class="btn" onclick="openItemAnalysisModal()">📊 Item Analysis</button>
    <?php endif; ?>
  </form>
</div>
<?php if ($sel && $summary): ?>
<div class="grid two">
  <div class="card stat"><div class="n"><?= $summary['takers'] ?></div><div class="l">Takers</div></div>
  <div class="card stat"><div class="n"><?= $summary['average'] ?>%</div><div class="l">Average (summary)</div></div>
  <div class="card stat"><div class="n" style="color:var(--ok)">🏆 <?= $summary['highest'] ?>%</div><div class="l">Highest — <?= e($summary['top']) ?></div></div>
  <div class="card stat"><div class="n" style="color:var(--bad)"><?= $summary['lowest'] ?>%</div><div class="l">Lowest — <?= e($summary['low']) ?></div></div>
</div>
<?php if ($mpl !== null): ?>
<div class="grid two">
  <div class="card stat"><div class="n"><?= $mpl['cut'] ?> pts</div><div class="l">MPL — minimum proficiency level (60% of <?= e($mpl['total']) ?>)</div>
    <div class="l">Male: <b><?= $mpl['male']['reach'] ?>/<?= $mpl['male']['n'] ?></b> · Female: <b><?= $mpl['female']['reach'] ?>/<?= $mpl['female']['n'] ?></b> · All: <b><?= $mpl['all']['reach'] ?>/<?= $mpl['all']['n'] ?></b></div></div>
  <div class="card stat"><div class="n"><?= $mpl['all']['mps'] ?>%</div><div class="l">MPS — mean percentage score</div>
    <div class="l">Male: <b><?= $mpl['male']['mps'] === null ? '—' : $mpl['male']['mps'] . '%' ?></b> · Female: <b><?= $mpl['female']['mps'] === null ? '—' : $mpl['female']['mps'] . '%' ?></b></div></div>
</div>
<?php endif; ?>
<?php endif; ?>
<?php if ($analysis): ?>
<div class="card no-print">
  <h3 style="margin-top:0">Question analysis
    <small class="hint">Top
      <?php foreach ([3, 5, 10] as $n): ?><a href="teacher_results.php?exam_id=<?= $sel ?>&topn=<?= $n ?>&sort=<?= $sort ?>&dir=<?= $sdir ?>"><?= $n ?></a><?= $n !== 10 ? ' · ' : '' ?><?php endforeach; ?>
    </small></h3>
  <div class="grid two">
    <div><h4 style="color:var(--ok)">✓ Easiest <?= $topn ?></h4>
      <ol><?php foreach (array_slice($analysis, 0, $topn) as $a): ?>
        <li><?= e(mb_strimwidth($a['text'], 0, 90, '…')) ?> — <b><?= $a['pct'] ?>%</b> <small class="hint">(<?= $a['got'] ?>/<?= $a['tries'] ?> correct)</small></li>
      <?php endforeach; ?></ol></div>
    <div><h4 style="color:var(--bad)">✗ Hardest <?= $topn ?></h4>
      <ol><?php foreach (array_slice(array_reverse($analysis), 0, $topn) as $a): ?>
        <li><?= e(mb_strimwidth($a['text'], 0, 90, '…')) ?> — <b><?= $a['pct'] ?>%</b> <small class="hint">(<?= $a['got'] ?>/<?= $a['tries'] ?> correct)</small></li>
      <?php endforeach; ?></ol></div>
  </div>
</div>
<?php endif; ?>
<?php if ($rows): ?>
<div class="card"><div class="table-wrap"><table>
  <tr><th>#</th><th><?= sort_link('Student', 'name') ?></th><th>Section</th><th><?= sort_link('Score', 'score') ?></th><th><?= sort_link('%', 'score') ?></th><th><?= sort_link('Submitted', 'date') ?></th><th></th></tr>
  <?php $i = 1; foreach ($rows as $r): ?>
  <tr><td><?= $i++ ?></td><td><?= e($r['fullname']) ?></td><td><?= e($r['section_name'] ?? '—') ?></td>
  <td><?= e($r['score']) ?>/<?= e($r['total']) ?></td><td><b><?= e($r['percentage']) ?>%</b></td><td><?= e(date('m-d-Y H:i:s', strtotime($r['submitted_at']))) ?></td>
  <td><div class="btnrow" style="margin:0;gap:4px">
    <a class="btn small ghost" href="teacher_review.php?attempt_id=<?= $r['id'] ?>">Review</a>
    <form method="post" style="display:inline" onsubmit="return confirm('Delete this attempt? Student can retake.')">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="delete_attempt">
      <input type="hidden" name="attempt_id" value="<?= $r['id'] ?>">
      <button class="btn small danger" type="submit">Delete</button>
    </form>
  </div></td></tr>
  <?php endforeach; ?>
</table></div></div>
<?php endif; ?>
<div id="remedial-modal" class="modal" style="display:none">
  <div class="modal-backdrop" onclick="closeRemedialModal()"></div>
  <div class="modal-content card" style="max-width:700px;width:90%;max-height:90vh;overflow:auto" onclick="event.stopPropagation()">
    <h3 style="margin-top:0">🩺 Create Remedial</h3>
    <p class="hint">Exam: <b><?= e($selTitle) ?></b> · <b><?= count($failed_students) ?></b> student(s) below passing</p>
    <form method="post" id="remedial-form" action="teacher_results.php?exam_id=<?= $sel ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="remedial">
      <input type="hidden" name="remedial_mode" value="existing">
      <label>Select Exam</label>
      <select name="target_exam_id" required>
        <?php foreach ($exams as $x): if ((int)$x['id'] === $sel): ?><option value="<?= $x['id'] ?>" selected><?= e($x['title']) ?></option><?php endif; endforeach; ?>
      </select>
      <div class="grid two" style="margin-top:12px">
        <div><label><input type="checkbox" name="shuffle_questions" value="1"> Shuffle questions</label></div>
        <div><label><input type="checkbox" name="allow_retake" value="1"> Allow retake</label></div>
      </div>
      <label>Assign to Students</label>
      <div style="max-height:200px;overflow:auto;border:1px solid var(--line);border-radius:8px;padding:8px">
        <?php foreach ($failed_students as $fs): ?>
        <label style="display:flex;align-items:center;gap:8px;padding:4px 0">
          <input type="checkbox" name="student_ids[]" value="<?= $fs['student_id'] ?>" checked>
          <span><?= e($fs['fullname']) ?> <small class="hint">(<?= e($fs['section_name'] ?? '—') ?> · <?= e($fs['percentage']) ?>%)</small></span>
        </label>
        <?php endforeach; ?>
      </div>
      <div class="btnrow">
        <button class="btn ok" type="submit">Assign Remedial</button>
        <button type="button" class="btn ghost" onclick="closeRemedialModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<div id="item-analysis-modal" class="modal" style="display:none">
  <div class="modal-backdrop" onclick="closeItemAnalysisModal()"></div>
  <div class="modal-content card" style="max-width:900px;width:95%;max-height:90vh;overflow:auto" onclick="event.stopPropagation()">
    <h3 style="margin-top:0">📊 Item Analysis: <?= e($selTitle) ?></h3>
    <p class="hint">Based on best attempt per student (<?= count($best_rows) ?> students)</p>
    <div style="max-height:60vh;overflow:auto">
      <table style="width:100%;border-collapse:collapse;font-size:.85rem">
        <thead style="position:sticky;top:0;background:var(--card);z-index:1">
          <tr style="border-bottom:2px solid var(--line)">
            <th style="text-align:left;padding:8px">#</th>
            <th style="text-align:left;padding:8px">Question</th>
            <th style="text-align:center;padding:8px">Type</th>
            <th style="text-align:center;padding:8px">Correct</th>
            <th style="text-align:center;padding:8px">Total</th>
            <th style="text-align:center;padding:8px">P-value</th>
            <th style="text-align:center;padding:8px">Discrim.</th>
            <th style="text-align:center;padding:8px">Distractors</th>
          </tr>
        </thead>
        <tbody>
          <?php $qnum = 1; foreach ($item_analysis as $ia): ?>
          <tr style="border-bottom:1px solid var(--line)">
            <td style="padding:8px"><?= $qnum++ ?></td>
            <td style="padding:8px;max-width:300px"><?= e(mb_strimwidth($ia['text'], 0, 120, '…')) ?></td>
            <td style="text-align:center;padding:8px"><span class="badge b-<?= $ia['qtype'] === 'mcq' ? 'draft' : ($ia['qtype'] === 'truefalse' ? 'published' : 'closed') ?>"><?= e($ia['qtype']) ?></span></td>
            <td style="text-align:center;padding:8px;color:var(--ok);font-weight:600"><?= $ia['got'] ?></td>
            <td style="text-align:center;padding:8px;color:var(--ink);font-weight:600"><?= $ia['tries'] ?></td>
            <td style="text-align:center;padding:8px">
              <span style="color:<?= $ia['pct'] < 30 ? 'var(--bad)' : ($ia['pct'] > 90 ? 'var(--warn)' : 'var(--ok)') ?>;font-weight:600"><?= $ia['pct'] ?>%</span>
            </td>
            <td style="text-align:center;padding:8px">
              <span style="color:var(--muted)">—</span>
              <small class="hint">Point-biserial</small>
            </td>
            <td style="padding:8px">
              <?php if ($ia['qtype'] === 'mcq' && !empty($ia['distractors'])): ?>
                <div style="font-size:.75rem;line-height:1.4">
                  <?php foreach (['A','B','C','D'] as $opt): if (!empty($ia['distractors'][$opt])): $d = $ia['distractors'][$opt]; ?>
                    <div style="display:flex;gap:4px;align-items:center">
                      <span style="font-weight:<?= $d['is_correct'] ? '700' : '400' ?>;color:<?= $d['is_correct'] ? 'var(--ok)' : 'var(--ink)' ?>"><?= $opt ?>.</span>
                      <span style="color:var(--muted)"><?= $d['pct'] ?>%</span>
                      <?= $d['is_correct'] ? '<span class="badge b-published" style="font-size:.6rem">key</span>' : '' ?>
                    </div>
                  <?php endif; endforeach; ?>
                </div>
              <?php else: ?>
                <span class="hint">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="btnrow" style="margin-top:12px">
      <button class="btn ghost" onclick="closeItemAnalysisModal()">Close</button>
    </div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('remedial-modal');
  window.openRemedialModal = function () { modal.style.display = 'block'; document.body.style.overflow = 'hidden'; };
  window.closeRemedialModal = function () { modal.style.display = 'none'; document.body.style.overflow = ''; };
})();
(function () {
  var modal = document.getElementById('item-analysis-modal');
  window.openItemAnalysisModal = function () { modal.style.display = 'block'; document.body.style.overflow = 'hidden'; };
  window.closeItemAnalysisModal = function () { modal.style.display = 'none'; document.body.style.overflow = ''; };
})();
</script>
<style>
.modal { position:fixed; top:0; left:0; right:0; bottom:0; z-index:100; display:flex; align-items:center; justify-content:center; padding:20px; }
.modal-backdrop { position:absolute; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.5); }
.modal-content { position:relative; background:var(--card); border-radius:var(--radius); box-shadow:0 20px 40px rgba(0,0,0,.2); }
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>