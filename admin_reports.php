<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('admin');

$exams = db()->query("SELECT e.*, u.fullname AS teacher_name, s.name AS section_name,
    (SELECT COUNT(*) FROM questions q WHERE q.exam_id=e.id) AS qcount
    FROM exams e LEFT JOIN users u ON u.id=e.teacher_id LEFT JOIN sections s ON s.id=e.section_id
    ORDER BY e.created_at DESC")->fetchAll();

$sel = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : (int)($exams[0]['id'] ?? 0);
$rows = []; $summary = null;
if ($sel > 0) {
    $st = db()->prepare("SELECT a.*, u.fullname, u.username, sec.name AS section_name
        FROM attempts a JOIN users u ON u.id=a.student_id LEFT JOIN sections sec ON sec.id=u.section_id
        WHERE a.exam_id=? AND a.submitted_at IS NOT NULL ORDER BY a.percentage DESC");
    $st->execute([$sel]); $rows = $st->fetchAll();
    if ($rows) {
        $perc = array_column($rows, 'percentage');
        $summary = [
            'takers'  => count($rows),
            'average' => round(array_sum($perc) / count($perc), 2),
            'highest' => max($perc),
            'lowest'  => min($perc),
            'top'     => $rows[0]['fullname'],
            'low'     => end($rows)['fullname'],
        ];
    }
}
$title = 'Reports';
include __DIR__ . '/includes/header.php';
?>
<div class="card">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap">
    <select name="exam_id" style="flex:1;min-width:220px">
      <?php foreach ($exams as $x): ?>
        <option value="<?= $x['id'] ?>" <?= $sel === (int)$x['id'] ? 'selected' : '' ?>>
          <?= e($x['title']) ?> (<?= $x['qcount'] ?> Qs, <?= e($x['status']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">View Report</button>
    <?php if ($rows): ?><button class="btn ghost" type="button" onclick="window.print()">🖨 Print</button><?php endif; ?>
  </form>
</div>
<?php if ($sel > 0 && $summary): ?>
<div class="grid two">
  <div class="card stat"><div class="n"><?= $summary['takers'] ?></div><div class="l">Students took exam</div></div>
  <div class="card stat"><div class="n"><?= $summary['average'] ?>%</div><div class="l">Average score</div></div>
  <div class="card stat"><div class="n" style="color:var(--ok)">🏆 <?= $summary['highest'] ?>%</div><div class="l">Highest — <?= e($summary['top']) ?></div></div>
  <div class="card stat"><div class="n" style="color:var(--bad)"><?= $summary['lowest'] ?>%</div><div class="l">Lowest — <?= e($summary['low']) ?></div></div>
</div>
<div class="card"><h3 style="margin-top:0">Score of students</h3><div class="table-wrap"><table>
  <tr><th>#</th><th>Student</th><th>Section</th><th>Score</th><th>%</th><th>Submitted</th></tr>
  <?php $i = 1; foreach ($rows as $r): ?>
  <tr><td><?= $i++ ?></td><td><?= e($r['fullname']) ?> <small class="hint"><?= e($r['username']) ?></small></td>
  <td><?= e($r['section_name'] ?? '—') ?></td><td><?= e($r['score']) ?>/<?= e($r['total']) ?></td>
  <td><b><?= e($r['percentage']) ?>%</b></td><td><?= e(date('m-d-Y H:i:s', strtotime($r['submitted_at']))) ?></td></tr>
  <?php endforeach; ?>
</table></div></div>
<?php elseif ($sel > 0): ?>
  <div class="card"><p class="hint">No submissions yet for this exam.</p></div>
<?php else: ?>
  <div class="card"><p class="hint">No exams yet.</p></div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
