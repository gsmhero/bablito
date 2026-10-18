<?php
declare(strict_types=1);

const TOTALS_SQL = '(SELECT contract_id, SUM(price) AS total_price, SUM(days) AS total_days, COUNT(*) AS steps FROM todos GROUP BY contract_id)';

/** Public listing card (contracts page, watch later). */
function listing_card(array $c, ?int $watchUserId = null): void
{
    $img = row("SELECT stored_name FROM files WHERE contract_id = ? AND kind = 'image' ORDER BY id LIMIT 1", [$c['id']]);
    $answers = (int)val("SELECT COUNT(*) FROM comments WHERE contract_id = ? AND scope = 'public'", [$c['id']]);
    $offers = (int)val('SELECT COUNT(*) FROM offers WHERE contract_id = ?', [$c['id']]);
    $watched = $watchUserId ? (bool)val('SELECT 1 FROM watch_later WHERE user_id = ? AND contract_id = ?', [$watchUserId, $c['id']]) : false;
    ?>
<article class="card listing">
  <a class="thumb" href="/contract/<?= (int)$c['id'] ?>">
    <?php if ($img): ?><img src="/uploads/<?= h($img['stored_name']) ?>" alt=""><?php else: ?><span>image</span><?php endif; ?>
  </a>
  <div class="listing-body">
    <div class="row between">
      <h3><a href="/contract/<?= (int)$c['id'] ?>"><?= h($c['title']) ?></a></h3>
      <span class="small muted"><?= h(fdate($c['created_at'])) ?></span>
    </div>
    <div class="row between">
      <span class="small"><a class="green" href="/user/<?= (int)$c['user_id'] ?>"><?= h($c['author_name']) ?></a> <?= stars((float)$c['rating_avg'], (int)$c['rating_count']) ?></span>
      <?php if ($c['category']): ?><span class="chip"><?= h($c['category']) ?></span><?php endif; ?>
    </div>
    <div class="small muted"><?= h($c['location']) ?></div>
    <p class="desc"><?= h(mb_strimwidth((string)$c['description'], 0, 260, '…')) ?></p>
    <div class="listing-foot">
      <div class="steps"><b><?= (int)$c['steps'] ?></b><span class="small">Steps</span></div>
      <div class="price-box">
        <?php if ($c['prepay_required']): ?><span class="pill blue">Prepaid</span><?php endif; ?>
        <div class="price"><?= money($c['total_price']) ?></div><div class="cur">RUB</div>
      </div>
    </div>
    <div class="row between tiny muted">
      <span><?= (int)$c['views'] ?> views · <?= $offers ?> offers · <?= $answers ?> comments</span>
      <span>
        <?php if ($watchUserId): ?>
          <form method="post" action="/contract/<?= (int)$c['id'] ?>/watch" class="inline"><?= csrf_field() ?><button class="linkbtn green" type="submit"><?= $watched ? 'Remove from watch later' : 'Watch later' ?></button></form>
        <?php endif; ?>
        · Contract ID: <?= str_pad((string)$c['id'], 3, '0', STR_PAD_LEFT) ?>
      </span>
    </div>
  </div>
</article>
<?php
}

function listing_select(): string
{
    return 'SELECT c.*, cat.name AS category, t.total_price, t.total_days, COALESCE(t.steps,0) AS steps,
            COALESCE(NULLIF(u.name,\'\'), CONCAT(\'User #\', u.id)) AS author_name, u.rating_avg, u.rating_count
            FROM contracts c
            JOIN users u ON u.id = c.user_id
            LEFT JOIN categories cat ON cat.id = c.category_id
            LEFT JOIN ' . TOTALS_SQL . ' t ON t.contract_id = c.id ';
}
