<?php
declare(strict_types=1);

$u = require_login();
$tab = $_GET['tab'] ?? 'posted';
if (!in_array($tab, ['posted', 'drafts', 'active', 'archive'], true)) {
    $tab = 'posted';
}
$sel = listing_sel_my();

function listing_sel_my(): string
{
    return 'SELECT c.*, cat.name AS category, t.total_price, COALESCE(t.steps,0) AS steps,
        (SELECT COUNT(*) FROM offers o WHERE o.contract_id = c.id) AS offer_count,
        (SELECT COUNT(*) FROM todos x WHERE x.contract_id = c.id AND x.accepted_at IS NOT NULL) AS accepted_steps
        FROM contracts c
        LEFT JOIN categories cat ON cat.id = c.category_id
        LEFT JOIN (SELECT contract_id, SUM(price) AS total_price, COUNT(*) AS steps FROM todos GROUP BY contract_id) t ON t.contract_id = c.id ';
}

$where = match ($tab) {
    'posted' => "c.user_id = :u AND c.status = 'published'",
    'drafts' => "c.user_id = :u AND c.status = 'draft'",
    'active' => "(c.user_id = :u OR c.contractor_id = :u) AND c.status IN ('active','paused')",
    default => "(c.user_id = :u OR c.contractor_id = :u) AND c.status IN ('fulfilled','terminated')",
};
$st = db()->prepare($sel . " WHERE $where ORDER BY c.updated_at DESC");
$st->execute(['u' => $u['id']]);
$list = $st->fetchAll();

render('My contracts', function () use ($list, $tab, $u) {
    $tabs = ['posted' => 'POSTED', 'drafts' => 'DRAFTS', 'active' => 'ACTIVE', 'archive' => 'ARCHIVE']; ?>
<div class="wrap">
  <div class="tabs">
    <?php foreach ($tabs as $k => $l): ?><a class="<?= $k === $tab ? 'on' : '' ?>" href="/my?tab=<?= $k ?>"><?= $l ?></a><?php endforeach; ?>
  </div>
  <?php if (!$list): ?><p class="muted">Nothing here yet.</p><?php endif; ?>
  <?php foreach ($list as $c):
      $isMine = (int)$c['user_id'] === (int)$u['id'];
      $dead = $c['agreed_deadline'] ?: $c['deadline'];
      [$lbl, $cls] = status_label($c['status']);
      $offers = ($tab === 'posted' && $isMine) ? rows('SELECT o.*, COALESCE(NULLIF(x.name,\'\'), CONCAT(\'User #\', x.id)) AS oname, x.rating_avg, x.rating_count FROM offers o JOIN users x ON x.id = o.user_id WHERE o.contract_id = ? ORDER BY o.price', [$c['id']]) : []; ?>
    <article class="card my-card <?= $tab === 'archive' ? 'faded' : '' ?>">
      <h3><a href="/contract/<?= (int)$c['id'] ?>"><?= h($c['title']) ?></a></h3>
      <div class="row gap small muted">
        <span class="pill <?= h($cls) ?>"><?= h($lbl) ?></span>
        <span><?= h(fdate($c['created_at'])) ?></span>
        <?php if ($c['category']): ?><span class="chip"><?= h($c['category']) ?></span><?php endif; ?>
        <span><?= h($c['location']) ?></span>
        <?php if ($c['agreed_deadline']): ?><span class="grow right">Deadline <?= h(fdate($dead)) ?></span><?php endif; ?>
      </div>
      <p class="desc small"><?= h(mb_strimwidth((string)$c['description'], 0, 220, '…')) ?></p>
      <?php if (in_array($c['status'], ['active', 'paused', 'fulfilled'], true)): $pct = $c['steps'] ? round($c['accepted_steps'] / $c['steps'] * 100) : 0; ?>
        <div class="progress"><span style="width:<?= $pct ?>%"></span></div>
        <div class="blue small"><b><?= (int)$c['accepted_steps'] ?>/<?= (int)$c['steps'] ?></b></div>
      <?php endif; ?>
      <div class="row between">
        <span><b class="mid <?= in_array($c['status'], ['active', 'paused'], true) ? 'blue' : 'green' ?>"><?= money($c['agreed_price'] ?? $c['total_price']) ?></b> <span class="small"><?= h(cur_code($c)) ?></span> &nbsp; <b class="mid"><?= (int)$c['steps'] ?></b> <span class="tiny">STEPS</span></span>
        <span class="tiny muted"><?= (int)$c['views'] ?> views · <?= (int)$c['offer_count'] ?> offers</span>
      </div>
      <?php if ($offers): ?>
        <table class="offers">
          <?php foreach ($offers as $o): ?>
            <tr>
              <td><a class="blue" href="/user/<?= (int)$o['user_id'] ?>"><?= h($o['oname']) ?></a> <?= stars((float)$o['rating_avg'], (int)$o['rating_count'], false) ?></td>
              <td><a class="offer-link" href="/offer/<?= (int)$o['id'] ?>"><b class="mid blue"><?= money($o['price']) ?></b> <?= h(cur_code($c)) ?></a></td>
              <td><a class="offer-link" href="/offer/<?= (int)$o['id'] ?>"><b class="mid blue"><?= (int)$o['days'] ?></b> days</a></td>
              <td><a class="btn small" href="/offer/<?= (int)$o['id'] ?>"><?= h(ucfirst($o['status'])) ?></a></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</div>
<?php });
