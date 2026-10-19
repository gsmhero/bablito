<?php
declare(strict_types=1);

// Stopwatch data for the two participants (and admin): live state as JSON, and the work log.
$u = require_login();
$c = load_contract((int)$args[0]);
if (!is_participant($c) && !is_admin()) {
    forbidden();
}

if ($args[1] === 'timer.json') {
    $t = row('SELECT timer_seconds + IF(timer_started_at IS NULL, 0, TIMESTAMPDIFF(SECOND, timer_started_at, NOW())) AS s, timer_started_at IS NOT NULL AS running FROM contracts WHERE id = ?', [$c['id']]);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['s' => (int)$t['s'], 'running' => (bool)$t['running'] && $c['status'] === 'active']);
    exit;
}

function hms(int $s): string
{
    return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
}

$log = rows('SELECT l.*, IF(l.stopped_at IS NULL, TIMESTAMPDIFF(SECOND, l.started_at, NOW()), l.seconds) AS secs, COALESCE(NULLIF(u.name, \'\'), CONCAT(\'User #\', u.id)) AS uname
    FROM timer_log l JOIN users u ON u.id = l.user_id WHERE l.contract_id = ? ORDER BY l.id DESC', [$c['id']]);
$total = array_sum(array_map(fn($r) => (int)$r['secs'], $log));

$body = function () use ($c, $log, $total) { ?>
<div class="timelog">
  <h2>Stopwatch log</h2>
  <div class="muted small"><?= h($c['title']) ?></div>
  <?php if (!$log): ?>
    <p class="muted">Nothing logged yet. Entries appear when the contractor presses Start.</p>
  <?php else: ?>
    <table class="admin-table">
      <tr><th>Date</th><th>Current task</th><th>Time</th></tr>
      <?php foreach ($log as $r): ?>
        <tr>
          <td><?= h(fdatetime($r['started_at'])) ?></td>
          <td><?= h($r['todo_name'] !== '' ? $r['todo_name'] : '-') ?></td>
          <td><?= h(hms((int)$r['secs'])) ?><?= $r['stopped_at'] === null ? ' <span class="pill blue">running</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <tr><td></td><td class="right"><b>Total</b></td><td><b><?= h(hms($total)) ?></b></td></tr>
    </table>
  <?php endif; ?>
</div>
<?php };

if (isset($_GET['modal'])) {
    $body();
    exit;
}
render('Stopwatch log', function () use ($body) { echo '<div class="narrow wide">'; $body(); echo '</div>'; }, ['back' => '/contract/' . (int)$c['id']]);
