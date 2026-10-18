<?php
declare(strict_types=1);

$id = (int)$args[0];
$c = load_contract($id);
if (!can_see_contract($c)) {
    $c['status'] === 'draft' || user() ? forbidden() : require_login();
}
$u = user();
$isOwner = $u && (int)$c['user_id'] === (int)$u['id'];
$isContractor = $u && (int)($c['contractor_id'] ?? 0) === (int)$u['id'];
$author = row('SELECT * FROM users WHERE id = ?', [$c['user_id']]);
$contractor = $c['contractor_id'] ? row('SELECT * FROM users WHERE id = ?', [$c['contractor_id']]) : null;
$todos = rows('SELECT * FROM todos WHERE contract_id = ? ORDER BY position, id', [$id]);
$files = rows('SELECT * FROM files WHERE contract_id = ? ORDER BY id', [$id]);
$working = in_array($c['status'], ['active', 'paused', 'fulfilled', 'terminated'], true);

if (!$working && !$isOwner && empty($_SESSION['viewed'][$id])) {
    q('UPDATE contracts SET views = views + 1 WHERE id = ?', [$id]);
    $_SESSION['viewed'][$id] = 1;
    $c['views']++;
}

if ($working) {
    require __DIR__ . '/_contract_work.php';
    exit;
}

$tot = contract_totals($id);
$offers = $isOwner ? rows('SELECT o.*, COALESCE(NULLIF(u.name,\'\'), CONCAT(\'User #\', u.id)) AS oname, u.rating_avg, u.rating_count FROM offers o JOIN users u ON u.id = o.user_id WHERE o.contract_id = ? ORDER BY o.created_at', [$id]) : [];
$myOffer = ($u && !$isOwner) ? row('SELECT id FROM offers WHERE contract_id = ? AND user_id = ?', [$id, $u['id']]) : null;
$offerCount = (int)val('SELECT COUNT(*) FROM offers WHERE contract_id = ?', [$id]);
$comments = comments_for("cm.contract_id = ? AND cm.scope = 'public'", [$id]);
$canOffer = $u && !$isOwner && !$myOffer && $c['status'] === 'published' && $c['accept_offers'];

render($c['title'], function () use ($c, $author, $todos, $files, $tot, $offers, $myOffer, $offerCount, $comments, $canOffer, $isOwner, $u) { ?>
<div class="wrap two-col">
  <div class="col">
    <?php if ($isOwner || is_admin()): ?><div class="right"><a class="btn small" href="/contract/<?= (int)$c['id'] ?>/edit">Edit Contract</a></div><?php endif; ?>
    <h1><?= h($c['title']) ?></h1>
    <?php if ($c['status'] === 'draft'): ?><span class="pill grey">Draft</span><?php endif; ?>
    <div class="row between">
      <span><a class="green big-link" href="/user/<?= (int)$author['id'] ?>"><?= h(public_name($author)) ?></a> <?= stars((float)$author['rating_avg'], (int)$author['rating_count']) ?></span>
      <span class="small muted">Creation Date <?= h(fdate($c['created_at'])) ?></span>
    </div>
    <div class="row between">
      <span class="muted big-link"><?= h($c['location']) ?></span>
      <span><?php if ($c['category']): ?><span class="chip"><?= h($c['category']) ?></span><?php endif; ?> <?php if ($c['prepay_required']): ?><span class="pill blue">Prepaid</span><?php endif; ?></span>
    </div>
    <hr>
    <div class="row between"><h3>Description</h3><span class="tiny muted"><?= (int)$c['views'] ?> views · <?= count($comments) ?> comments</span></div>
    <p><?= nl2br(h($c['description'])) ?></p>
    <hr>
    <h3>Contract</h3>
    <?php if ($canOffer): ?><form method="post" action="/contract/<?= (int)$c['id'] ?>/offer" id="offer-form"><?= csrf_field() ?></form><?php endif; ?>
    <?php foreach ($todos as $t): ?>
        <div class="card todo-view">
          <h4><?= h($t['name']) ?></h4>
          <p class="small"><?= nl2br(h($t['description'])) ?></p>
          <div class="row gap">
            <div><div class="tiny">Price</div><div class="mid green"><?= money($t['price']) ?></div></div>
            <div><div class="tiny">Days</div><div class="mid grey"><?= (int)$t['days'] ?></div></div>
            <?php if ($canOffer): ?>
              <label class="tiny">Offer price <input form="offer-form" name="otodo[<?= (int)$t['id'] ?>][price]" class="o-price" inputmode="decimal"></label>
              <label class="tiny">Offer Days <input form="offer-form" name="otodo[<?= (int)$t['id'] ?>][days]" class="o-days" type="number" min="1"></label>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
  </div>

  <div class="col">
    <hr>
    <div class="gallery">
      <?php foreach ($files as $f): if ($f['kind'] !== 'image') { continue; } ?><figure><a href="/uploads/<?= h($f['stored_name']) ?>" target="_blank"><img src="/uploads/<?= h($f['stored_name']) ?>" alt=""></a></figure><?php endforeach; ?>
    </div>
    <h3>Files</h3>
    <ul class="files"><?php foreach ($files as $f): if ($f['kind'] !== 'file') { continue; } ?><li><a href="/uploads/<?= h($f['stored_name']) ?>" download="<?= h($f['original_name']) ?>"><?= h($f['original_name']) ?></a></li><?php endforeach; ?></ul>
    <hr>
    <div class="sums">
      <div><div class="lbl">Price</div><div class="big green"><?= money($tot['price']) ?></div><div class="cur">RUB</div></div>
      <div class="center"><div class="lbl">Time days</div><div class="big green"><?= $tot['days'] ?></div></div>
      <div class="center"><div class="lbl">Till Date</div><div class="big green sm"><?= h(fdate($c['deadline'])) ?></div></div>
    </div>
    <hr>
    <?php if ($canOffer): ?>
      <div class="row gap end">
        <label class="tiny">Price <input form="offer-form" name="price" id="offer-price" inputmode="decimal"></label>
        <label class="tiny">Time days <input form="offer-form" name="days" id="offer-days" type="number" min="1" class="short"></label>
        <button form="offer-form" class="btn green-outline" type="submit">Make Offer</button>
      </div>
      <label class="small">Notes <textarea form="offer-form" name="notes" rows="4"></textarea></label>
      <p class="tiny muted">Either fill an offer price and days for every To Do, or enter one total price and days.</p>
    <?php elseif ($myOffer): ?>
      <p><a class="btn" href="/offer/<?= (int)$myOffer['id'] ?>">View my offer</a></p>
    <?php elseif (!$u && $c['status'] === 'published'): ?>
      <p><a href="/login">Log in</a> to make an offer.</p>
    <?php endif; ?>

    <?php if ($isOwner && $offers): ?>
      <h3>Offers (<?= count($offers) ?>)</h3>
      <table class="offers">
        <?php foreach ($offers as $o): ?>
          <tr>
            <td><a class="blue" href="/user/<?= (int)$o['user_id'] ?>"><?= h($o['oname']) ?></a> <?= stars((float)$o['rating_avg'], (int)$o['rating_count'], false) ?></td>
            <td><b class="blue"><?= money($o['price']) ?></b> RUB</td>
            <td><b class="blue"><?= (int)$o['days'] ?></b> days</td>
            <td><a class="btn small" href="/offer/<?= (int)$o['id'] ?>"><?= h(ucfirst($o['status'])) ?></a></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

    <?php if ($u && $c['status'] === 'published'): ?>
      <form method="post" action="/contract/<?= (int)$c['id'] ?>/watch" class="right"><?= csrf_field() ?><button class="linkbtn green" type="submit">Watch later</button></form>
    <?php endif; ?>

    <?php if ($c['status'] === 'published'): ?>
    <div id="comments">
      <h3>Comments <span class="badge"><?= count($comments) ?></span></h3>
      <?php render_comments($comments, (int)$c['user_id']); ?>
      <?php if ($u): ?>
        <form method="post" action="/contract/<?= (int)$c['id'] ?>/comment" class="comment-form">
          <?= csrf_field() ?>
          <input name="body" maxlength="4000" required placeholder="Write a comment">
          <button class="btn small" type="submit">Post Comment</button>
        </form>
      <?php else: ?><p class="small muted"><a href="/login">Log in</a> to comment.</p><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php });
