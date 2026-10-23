<?php
declare(strict_types=1);

$u = require_login();

if (($args[0] ?? '') === 'live') {
    header('Cache-Control: no-store');
    echo live_feed_html();
    exit;
}

require_once ROOT . '/app/card.php';

$st = db()->prepare("SELECT c.*, " . cat_name_sql() . " AS category, au.name AS a_name, au.show_name AS a_show, ct.name AS k_name, ct.show_name AS k_show,
        (SELECT COUNT(*) FROM todos t WHERE t.contract_id = c.id) AS steps,
        (SELECT COUNT(*) FROM todos t WHERE t.contract_id = c.id AND t.accepted_at IS NOT NULL) AS accepted_steps,
        (SELECT COUNT(*) FROM comments cm WHERE cm.contract_id = c.id AND cm.scope IN ('work','todo') AND cm.user_id <> :me
            AND cm.id > COALESCE((SELECT r.last_id FROM comment_reads r WHERE r.user_id = :me AND r.contract_id = c.id), 0)) AS unread,
        (SELECT cc.timezone FROM contract_clocks cc WHERE cc.user_id = :me AND cc.contract_id = c.id) AS my_clock_tz,
        (SELECT o.timezone FROM users o WHERE o.id = IF(c.user_id = :me, c.contractor_id, c.user_id)) AS other_tz
    FROM contracts c LEFT JOIN categories cat ON cat.id = c.category_id JOIN users au ON au.id = c.user_id LEFT JOIN users ct ON ct.id = c.contractor_id
    WHERE (c.user_id = :me OR c.contractor_id = :me) AND c.status IN ('active','paused')
    ORDER BY COALESCE(c.agreed_deadline, c.deadline) IS NULL, COALESCE(c.agreed_deadline, c.deadline), c.id");
$st->execute(['me' => $u['id']]);
$list = $st->fetchAll();

$totals = [];
foreach ($list as $c) {
    $k = cur_code($c);
    $totals[$k] = ($totals[$k] ?? 0) + (float)$c['agreed_price'];
}
$myTz = valid_timezone($u['timezone'] ?? null);

render('Dashboard', function () use ($list, $totals, $myTz, $u) { ?>
<div class="wrap">
  <div class="dash-grid">
    <div class="dash-main">
      <div class="dash-head">
        <div>
          <h1 class="dash-title">Dashboard</h1>
          <div class="dash-clock small" id="dash-clock" data-tz="<?= h($myTz ?? '') ?>"><span data-k="wd"></span> &nbsp; <span data-k="date"></span> &nbsp; Time: <span data-k="time"></span></div>
        </div>
        <a class="btn big dash-btn" href="/my">My Contracts</a>
      </div>
      <hr>
      <?php if (!$list): ?>
        <p class="muted">You have no active contracts yet. <a href="/contracts">Browse contracts</a> or <a href="/contract/new">create one</a>.</p>
      <?php endif; ?>
      <?php foreach ($list as $c):
          $isAuthor = (int)$c['user_id'] === (int)$u['id'];
          $dead = $c['agreed_deadline'] ?: $c['deadline'];
          $pct = $c['steps'] ? round($c['accepted_steps'] / $c['steps'] * 100) : 0;
          $running = $c['status'] === 'active' && $c['timer_started_at'];
          $tz = valid_timezone($c['my_clock_tz'] ?? null) ?? valid_timezone($c['other_tz'] ?? null); ?>
        <div class="dash-row">
          <a class="card dash-card" href="/contract/<?= (int)$c['id'] ?>">
            <div class="row between">
              <h3><?= h($c['title']) ?></h3>
              <div class="right"><div class="dl <?= is_overdue($c) ? 'red' : '' ?>"><?= h(fdate($dead)) ?></div><div class="tiny">Deadline date</div></div>
            </div>
            <div class="row gap small">
              <span class="pill <?= $c['status'] === 'paused' ? 'orange' : 'blue' ?>"><?= $c['status'] === 'paused' ? 'Paused' : 'Active' ?></span>
              <?php if ($running): ?><span class="pill blue">running</span><?php endif; ?>
              <?php $other = $isAuthor ? ['id' => $c['contractor_id'], 'name' => $c['k_name'], 'show_name' => $c['k_show']] : ['id' => $c['user_id'], 'name' => $c['a_name'], 'show_name' => $c['a_show']]; ?>
              <span class="<?= $isAuthor ? 'blue' : 'green' ?>" title="<?= $isAuthor ? 'Contractor' : 'Client (contract author)' ?>"><?= h(public_name($other)) ?></span>
            </div>
            <div class="progress"><span style="width:<?= $pct ?>%"></span></div>
            <div class="row between">
              <span><b class="mid blue"><?= money($c['agreed_price']) ?></b> <span class="small"><?= h(cur_code($c)) ?></span></span>
              <span class="small">Unread Comments <span class="badge <?= (int)$c['unread'] === 0 ? 'zero' : '' ?>"><?= (int)$c['unread'] ?></span></span>
              <b class="blue"><?= (int)$c['accepted_steps'] ?>/<?= (int)$c['steps'] ?></b>
            </div>
          </a>
          <?php if ($tz): ?>
            <div class="card wclock" data-tz="<?= h($tz) ?>">
              <a class="gear" href="/contract/<?= (int)$c['id'] ?>/clock" data-modal title="Set city" aria-label="Set city"><?= gear_icon() ?></a>
              <div class="row between tiny"><span data-k="wd"></span><span data-k="date"></span></div>
              <div class="wtime"><span data-k="hh"></span><span class="colon">:</span><span data-k="mm"></span></div>
              <div class="wcity"><?= h(tz_label($tz)) ?></div>
            </div>
          <?php else: ?>
            <div class="card wclock empty">
              <a class="gear" href="/contract/<?= (int)$c['id'] ?>/clock" data-modal title="Set city" aria-label="Set city"><?= gear_icon() ?></a>
              <div class="tiny muted">Where is the other side? Click the gear to choose a city.</div>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($list): ?>
        <div class="dash-total">
          <?php foreach ($totals as $code => $sum): ?>
            <span class="mid blue"><?= h(CURRENCIES[$code][0]) ?> <?= money($sum) ?></span>
          <?php endforeach; ?>
          <span class="small">Total</span>
        </div>
      <?php endif; ?>
    </div>

    <aside class="live-box">
      <div class="row between"><b class="small">CONTRACTS</b><span class="live-badge">LIVE</span></div>
      <div id="live-feed" data-url="/dashboard/live"><?= live_feed_html() ?></div>
    </aside>
  </div>
</div>
<?php });
