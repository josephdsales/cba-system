<?php
// Teacher: INDIVIDUAL REPORT ON THE RESULTS ... - MPL
// Opened in a new window from teacher_results.php. Shows the MPL table:
//   MPL of Male takers   = male learners who obtained the MPL / total number of takers * 100
//   MPL of Female takers = female learners who obtained the MPL / total number of takers * 100
//   Weighted MPL         = MPL of Male takers + MPL of Female takers
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';
$user = require_role('teacher');

$exam_id = (int)($_GET['exam_id'] ?? 0);
$st = db()->prepare('SELECT * FROM exams WHERE id=? AND teacher_id=?');
$st->execute([$exam_id, $user['id']]);
$exam = $st->fetch();
if (!$exam) { http_response_code(404); exit('Exam not found.'); }

// Best attempt per student (same rule used everywhere else for analysis).
$st = db()->prepare("SELECT a.*, u.fullname, u.gender FROM attempts a
    JOIN users u ON u.id=a.student_id
    JOIN (
        SELECT student_id, MAX(percentage) AS max_pct
        FROM attempts
        WHERE exam_id=? AND submitted_at IS NOT NULL
        GROUP BY student_id
    ) best ON best.student_id=a.student_id AND best.max_pct=a.percentage
    WHERE a.exam_id=? AND a.submitted_at IS NOT NULL");
$st->execute([$exam_id, $exam_id]);
$rows = $st->fetchAll();

$male_takers = 0; $female_takers = 0;
$male_mpl = 0; $female_mpl = 0;
$other_takers = 0;
$cut = 0; $total_pts = 0;

if ($rows) {
    $total_pts = (float)$rows[0]['total'];
    $cut = round($total_pts * 0.6, 2); // MPL = 60% of the total score
    foreach ($rows as $r) {
        $g = $r['gender'] ?? '';
        $got = ((float)$r['score'] >= $cut);
        if ($g === 'Male') {
            $male_takers++;
            if ($got) $male_mpl++;
        } elseif ($g === 'Female') {
            $female_takers++;
            if ($got) $female_mpl++;
        } else {
            $other_takers++; // not in the Male/Female counts, but still a taker
        }
    }
}

// Denominator for every percentage = TOTAL number of takers (all submissions).
$total_takers = count($rows);
$male_mpl_pct   = ($total_takers > 0) ? round($male_mpl / $total_takers * 100, 2) : 0;
$female_mpl_pct = ($total_takers > 0) ? round($female_mpl / $total_takers * 100, 2) : 0;
$weighted_mpl   = round($male_mpl_pct + $female_mpl_pct, 2);

$fmt = function ($n) { return rtrim(rtrim(number_format((float)$n, 2, '.', ','), '0'), '.'); };
$title = 'MPL Report';
$exam_label = trim((string)$exam['title']);
if ($exam_label === '') $exam_label = 'EXAM';
$report_title = 'INDIVIDUAL REPORT ON THE RESULTS OF '
    . (function_exists('mb_strtoupper') ? mb_strtoupper($exam_label, 'UTF-8') : strtoupper($exam_label))
    . ' - MPL';
include __DIR__ . '/includes/header.php';
?>
<div class="card print-only" style="text-align:center">
  <p style="margin:0;font-weight:700">DOÑA JUANA CHIOCO NATIONAL HIGH SCHOOL</p>
  <h2 style="margin:6px 0 4px"><?= e($report_title) ?></h2>
  <p class="hint" style="margin:0">Prepared by: <?= e($user['fullname']) ?> · Generated <?= date('m-d-Y H:i:s') ?></p>
</div>

<div class="card no-print">
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <a class="btn ghost" href="teacher_results.php?exam_id=<?= $exam_id ?>">← Back to results</a>
    <button class="btn" type="button" onclick="window.print()">🖨 Print / Save as PDF</button>
    <small class="hint">Opens a clean printout — use "Save as PDF" for a file copy.</small>
  </div>
</div>

<div class="card">
  <h3 style="margin-top:0"><?= e($report_title) ?></h3>
  <p class="hint" style="margin-top:-6px">
    Exam: <b><?= e($exam['title']) ?></b><?php if ($rows): ?> ·
    MPL: <b><?= $fmt($cut) ?> points</b> (60% of <?= $fmt($total_pts) ?>) ·
    Best attempt per student<?php endif; ?>
  </p>

  <?php if (!$rows): ?>
    <p class="hint">No submissions yet for this exam.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table style="min-width:900px">
      <tr>
        <th>Number of Male Takers</th>
        <th>Number of Female Takers</th>
        <th>Number of Male learners who obtained the MPL</th>
        <th>Number of Female learners who obtained the MPL</th>
        <th>Total Number of Takers</th>
        <th>MPL of Male Takers</th>
        <th>MPL of Female Takers</th>
        <th>Weighted MPL</th>
      </tr>
      <tr style="text-align:center">
        <td><?= $male_takers ?></td>
        <td><?= $female_takers ?></td>
        <td><?= $male_mpl ?></td>
        <td><?= $female_mpl ?></td>
        <td><b><?= $total_takers ?></b></td>
        <td><b><?= $fmt($male_mpl_pct) ?>%</b></td>
        <td><b><?= $fmt($female_mpl_pct) ?>%</b></td>
        <td><b><?= $fmt($weighted_mpl) ?>%</b></td>
      </tr>
    </table>
  </div>

  <h4 style="margin-bottom:4px">Computation</h4>
  <div class="hint" style="line-height:1.9">
    MPL of Male Takers = <?= $male_mpl ?> ÷ <?= $total_takers ?> × 100 = <b><?= $fmt($male_mpl_pct) ?>%</b><br>
    MPL of Female Takers = <?= $female_mpl ?> ÷ <?= $total_takers ?> × 100 = <b><?= $fmt($female_mpl_pct) ?>%</b><br>
    Weighted MPL = <?= $fmt($male_mpl_pct) ?>% + <?= $fmt($female_mpl_pct) ?>% = <b><?= $fmt($weighted_mpl) ?>%</b>
  </div>

  <?php if ($other_takers > 0): ?>
    <p class="hint" style="margin-top:10px">⚠ <?= $other_takers ?> taker(s) with no / other gender recorded are included in the Total Number of Takers but not in the Male / Female counts.</p>
  <?php endif; ?>
  <?php endif; ?>
</div>
<style>
@media print {
  /* Wide 8-column table must not be clipped by the scroll wrapper. */
  .table-wrap { overflow: visible !important; }
  table { font-size: .75rem; }
  th, td { padding: 6px 5px; }
}
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>
