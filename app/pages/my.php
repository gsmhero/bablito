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
    return 'SELECT c.*, ' . cat_name_sql() . ' AS category, t.total_price, COALESCE(t.steps,0) AS steps,
        (SELECT COUNT(*) FROM offers o WHERE o.contract_id = c.id) AS offer_count,
        (SELECT COUNT(*) FROM offers o WHERE o.contract_id = c.id AND o.status <> \'accepted\' AND o.seen_at IS NULL) AS new_offers,
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
      [$lbl, $cls] = status_label($c['status']); ?>
    <article class="card my-card <?= $tab === 'archive' ? 'faded' : '' ?>">
      <h3><a href="/contract/<?= (int)$c['id'] ?>"><?= h($c['title']) ?></a><?php if ($isMine && (int)$c['new_offers'] > 0): ?> <span class="new-circle" title="New offers you have not opened yet"><?= (int)$c['new_offers'] > 99 ? '99+' : (int)$c['new_offers'] ?></span><?php endif; ?></h3>
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
        <?php if ($tab === 'archive' && $isMine): ?><form method="post" action="/contract/<?= (int)$c['id'] ?>/repeat" class="inline" onsubmit="return confirm('Create a new draft from this contract?')"><?= csrf_field() ?><button class="btn small green" type="submit">Repeat Contract</button></form><?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php });
