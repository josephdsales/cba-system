<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('teacher');

$st = db()->prepare('SELECT * FROM exams WHERE teacher_id=? ORDER BY created_at DESC');
$st->execute([$user['id']]);
$exams = $st->fetchAll();
$sel = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : (int)($exams[0]['id'] ?? 0);
// security: selected exam must belong to this teacher
$mine = array_filter($exams, fn($x) => (int)$x['id'] === $sel);
if ($sel && !$mine) { http_response_code(403); exit('Forbidden'); }

$rows = []; $summary = null;
if ($sel) {
    $st = db()->prepare("SELECT a.*, u.fullname, u.username, s.name AS section_name
        FROM attempts a JOIN users u ON u.id=a.student_id LEFT JOIN sections s ON s.id=u.section_id
        WHERE a.exam_id=? AND a.submitted_at IS NOT NULL ORDER BY a.percentage DESC");
    $st->execute([$sel]); $rows = $st->fetchAll();
    if ($rows) {
        $perc = array_column($rows, 'percentage');
        $summary = ['takers' => count($rows), 'average' => round(array_sum($perc) / count($perc), 2),
            'highest' => max($perc), 'lowest' => min($perc),
            'top' => implode(', ', array_column(array_filter($rows, function ($r) use ($perc) { return (float)$r['percentage'] == (float)max($perc); }), 'fullname')),
            'low' => implode(', ', array_column(array_filter($rows, function ($r) use ($perc) { return (float)$r['percentage'] == (float)min($perc); }), 'fullname'))];
    }
}
$title = 'Exam Results';
$topn = isset($_GET['topn']) ? max(1, min(20, (int)$_GET['topn'])) : 5;
$analysis = [];
if ($sel && $rows) {
    $st = db()->prepare("SELECT q.id, q.question_text, COUNT(an.id) AS tries, COALESCE(SUM(an.is_correct),0) AS got
        FROM questions q LEFT JOIN answers an ON an.question_id=q.id
        LEFT JOIN attempts t ON t.id=an.attempt_id AND t.submitted_at IS NOT NULL
        WHERE q.exam_id=? AND (an.id IS NULL OR t.id IS NOT NULL)
        GROUP BY q.id, q.question_text");
    $st->execute([$sel]);
    foreach ($st->fetchAll() as $r) {
        $tries = (int)$r['tries'];
        if ($tries > 0) $analysis[] = ['text' => $r['question_text'], 'pct' => round($r['got'] / $tries * 100, 1), 'got' => $r['got'], 'tries' => $tries];
    }
    usort($analysis, function ($a, $b) { return $b['pct'] <=> $a['pct']; });
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
    <?php if ($rows): ?><a class="btn ghost" href="teacher_summary_download.php?exam_id=<?= $sel ?>">⬇ Download Summary (PDF)</a><?php endif; ?>
  </form>
</div>
<?php if ($sel && $summary): ?>
<div class="grid two">
  <div class="card stat"><div class="n"><?= $summary['takers'] ?></div><div class="l">Takers</div></div>
  <div class="card stat"><div class="n"><?= $summary['average'] ?>%</div><div class="l">Average (summary)</div></div>
  <div class="card stat"><div class="n" style="color:var(--ok)">🏆 <?= $summary['highest'] ?>%</div><div class="l">Highest — <?= e($summary['top']) ?></div></div>
  <div class="card stat"><div class="n" style="color:var(--bad)"><?= $summary['lowest'] ?>%</div><div class="l">Lowest — <?= e($summary['low']) ?></div></div>
</div>
<?php if ($analysis): ?>
<div class="card no-print">
  <h3 style="margin-top:0">Question analysis
    <small class="hint">Top
      <?php foreach ([3, 5, 10] as $n): ?><a href="teacher_results.php?exam_id=<?= $sel ?>&topn=<?= $n ?>"><?= $n ?></a><?= $n !== 10 ? ' · ' : '' ?><?php endforeach; ?>
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
<div class="card"><div class="table-wrap"><table>
  <tr><th>#</th><th>Student</th><th>Section</th><th>Score</th><th>%</th><th>Submitted</th><th></th></tr>
  <?php $i = 1; foreach ($rows as $r): ?>
  <tr><td><?= $i++ ?></td><td><?= e($r['fullname']) ?></td><td><?= e($r['section_name'] ?? '—') ?></td>
  <td><?= e($r['score']) ?>/<?= e($r['total']) ?></td><td><b><?= e($r['percentage']) ?>%</b></td><td><?= e(date('m-d-Y H:i:s', strtotime($r['submitted_at']))) ?></td>
  <td><a class="btn small ghost" href="teacher_review.php?attempt_id=<?= $r['id'] ?>">Review</a></td></tr>
  <?php endforeach; ?>
</table></div></div>
<?php elseif ($sel): ?><div class="card"><p class="hint">No submissions yet.</p></div>
<?php else: ?><div class="card"><p class="hint">No exams yet.</p></div><?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
