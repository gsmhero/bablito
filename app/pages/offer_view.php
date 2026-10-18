<?php
declare(strict_types=1);

$u = require_login();
$offer = row('SELECT o.*, COALESCE(NULLIF(u.name,\'\'), CONCAT(\'User #\', u.id)) AS oname, u.rating_avg, u.rating_count FROM offers o JOIN users u ON u.id = o.user_id WHERE o.id = ?', [(int)$args[0]]);
if (!$offer) {
    not_found();
}
$c = load_contract((int)$offer['contract_id']);
$isOwner = (int)$c['user_id'] === (int)$u['id'];
$isOfferer = (int)$offer['user_id'] === (int)$u['id'];
if (!$isOwner && !$isOfferer && !is_admin()) {
    forbidden();
}
$author = row('SELECT * FROM users WHERE id = ?', [$c['user_id']]);
$todos = rows('SELECT t.*, ot.price AS o_price, ot.days AS o_days FROM todos t LEFT JOIN offer_todos ot ON ot.todo_id = t.id AND ot.offer_id = ? WHERE t.contract_id = ? ORDER BY t.position, t.id', [$offer['id'], $c['id']]);
$files = rows('SELECT * FROM files WHERE contract_id = ? AND by_contractor = 0 ORDER BY id', [$c['id']]);
$tot = contract_totals((int)$c['id']);
$comments = comments_for("cm.scope = 'offer' AND cm.offer_id = ?", [$offer['id']]);
$editable = $isOfferer && $offer['status'] === 'pending' && $c['status'] === 'published';
$decidable = $isOwner && $offer['status'] === 'pending' && $c['status'] === 'published';
$hasSteps = (bool)array_filter($todos, fn($t) => $t['o_price'] !== null);

render('Offer', function () use ($offer, $c, $author, $todos, $files, $tot, $comments, $editable, $decidable, $hasSteps, $isOwner) { ?>
<form method="post" action="/offer/<?= (int)$offer['id'] ?>/edit" class="wrap two-col">
  <?= csrf_field() ?>
  <div class="col">
    <h1><a href="/contract/<?= (int)$c['id'] ?>" class="plain"><?= h($c['title']) ?></a></h1>
    <div class="muted"><?= h(ucfirst($offer['status'])) ?> offer</div>
    <div class="row between">
      <span><a class="green" href="/user/<?= (int)$author['id'] ?>"><?= h(public_name($author)) ?></a> <?= stars((float)$author['rating_avg'], (int)$author['rating_count']) ?></span>
      <span class="small muted"><?= h(fdate($c['created_at'])) ?> <?php if ($c['category']): ?><span class="chip"><?= h($c['category']) ?></span><?php endif; ?></span>
    </div>
    <div class="muted"><?= h($c['location']) ?></div>
    <hr>
    <h3>Description</h3>
    <p><?= nl2br(h($c['description'])) ?></p>
    <hr>
    <h3>Contract</h3>
    <?php foreach ($todos as $t): ?>
      <div class="card todo-view">
        <h4><?= h($t['name']) ?></h4>
        <p class="small"><?= nl2br(h($t['description'])) ?></p>
        <div class="row gap">
          <div><div class="tiny">Price</div><div class="mid green"><?= money($t['price']) ?></div></div>
          <div><div class="tiny">Days</div><div class="mid grey"><?= (int)$t['days'] ?></div></div>
          <?php if ($editable): ?>
            <label class="tiny">Offer price <input name="otodo[<?= (int)$t['id'] ?>][price]" value="<?= $t['o_price'] === null ? '' : h((string)(float)$t['o_price']) ?>" class="o-price" inputmode="decimal"></label>
            <label class="tiny">Offer days <input name="otodo[<?= (int)$t['id'] ?>][days]" value="<?= $t['o_days'] === null ? '' : (int)$t['o_days'] ?>" class="o-days" type="number" min="1"></label>
          <?php elseif ($t['o_price'] !== null): ?>
            <div><div class="tiny">Offer price</div><div class="mid blue"><?= money($t['o_price']) ?></div></div>
            <div><div class="tiny">Offer days</div><div class="mid blue"><?= (int)$t['o_days'] ?></div></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if ($decidable): ?>
    <div class="row between">
      <button class="btn green" type="submit" formaction="/offer/<?= (int)$offer['id'] ?>/accept" formnovalidate onclick="return confirm('Accept this offer? Other offers will be rejected.')">Accept Offer</button>
      <button class="btn red" type="submit" formaction="/offer/<?= (int)$offer['id'] ?>/reject" formnovalidate>Reject Offer</button>
    </div>
    <?php endif; ?>
  </div>
  <div class="col">
    <hr>
    <div class="gallery">
      <?php foreach ($files as $f): if ($f['kind'] !== 'image') { continue; } ?><figure><img src="/uploads/<?= h($f['stored_name']) ?>" alt=""></figure><?php endforeach; ?>
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
    <div class="sums">
      <div><div class="lbl">Offer Price</div>
        <?php if ($editable): ?><input name="price" id="offer-price" value="<?= h((string)(float)$offer['price']) ?>" inputmode="decimal" <?= $hasSteps ? 'readonly' : '' ?>><?php else: ?><div class="big blue"><?= money($offer['price']) ?></div><?php endif; ?>
        <div class="cur">RUB</div></div>
      <div class="center"><div class="lbl">Offer days</div>
        <?php if ($editable): ?><input name="days" id="offer-days" type="number" min="1" value="<?= (int)$offer['days'] ?>" <?= $hasSteps ? 'readonly' : '' ?>><?php else: ?><div class="big blue"><?= (int)$offer['days'] ?></div><?php endif; ?></div>
      <div class="center"><div class="lbl">Offer From</div><a class="blue" href="/user/<?= (int)$offer['user_id'] ?>"><?= h($offer['oname']) ?></a> <?= stars((float)$offer['rating_avg'], (int)$offer['rating_count']) ?></div>
    </div>
    <label class="small">Notes
      <?php if ($editable): ?><textarea name="notes" rows="4"><?= h($offer['notes']) ?></textarea><?php else: ?><div class="note-box"><?= nl2br(h($offer['notes'])) ?></div><?php endif; ?>
    </label>
    <?php if ($editable): ?>
    <div class="row right gap">
      <button class="btn" type="submit">Edit Offer</button>
      <button class="btn red" type="submit" formaction="/offer/<?= (int)$offer['id'] ?>/delete" formnovalidate onclick="return confirm('Delete your offer?')">Delete My Offer</button>
    </div>
    <?php endif; ?>
  </div>
</form>

<div class="wrap" id="comments">
  <h3>Comments</h3>
  <?php render_comments($comments, (int)$c['user_id'], true); ?>
  <form method="post" action="/offer/<?= (int)$offer['id'] ?>/comment" class="comment-form">
    <?= csrf_field() ?>
    <input name="body" maxlength="4000" required placeholder="Write a comment">
    <button class="btn small" type="submit">Post Comment</button>
  </form>
</div>
<?php });
