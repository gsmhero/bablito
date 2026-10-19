<?php
declare(strict_types=1);

require_once ROOT . '/app/offers.php';
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
$editing = $editable && isset($_GET['edit']);
if ($isOwner && !$offer['seen_at']) {
    q('UPDATE offers SET seen_at = NOW() WHERE id = ? AND seen_at IS NULL', [$offer['id']]);
}
$decidable = $isOwner && $offer['status'] === 'pending' && $c['status'] === 'published';
$extras = rows('SELECT * FROM offer_extra_todos WHERE offer_id = ? ORDER BY position, id', [$offer['id']]);
$hasSteps = $extras || (bool)array_filter($todos, fn($t) => $t['o_price'] !== null);

render('Offer', function () use ($offer, $c, $author, $todos, $files, $tot, $comments, $editable, $editing, $decidable, $hasSteps, $isOwner, $extras) { ?>
<form method="post" action="/offer/<?= (int)$offer['id'] ?>/edit" class="wrap two-col" id="offer-form">
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
        <div class="todo-fields">
          <div><div class="tiny">Price</div><div class="mid green"><?= h(cur_symbol($c)) ?>&nbsp;<?= money($t['price']) ?></div></div>
          <div><div class="tiny">Days</div><div class="mid grey"><?= (int)$t['days'] ?></div></div>
          <?php if ($editing): ?>
            <label class="tiny">Offer price <input name="otodo[<?= (int)$t['id'] ?>][price]" value="<?= $t['o_price'] === null ? '' : h((string)(float)$t['o_price']) ?>" class="num o-price" inputmode="decimal"></label>
            <label class="tiny">Offer days <input name="otodo[<?= (int)$t['id'] ?>][days]" value="<?= $t['o_days'] === null ? '' : (int)$t['o_days'] ?>" class="num o-days" type="number" min="0"></label>
          <?php elseif ($t['o_price'] !== null): ?>
            <div><div class="tiny">Offer price</div><div class="mid blue"><?= h(cur_symbol($c)) ?>&nbsp;<?= money($t['o_price']) ?></div></div>
            <div><div class="tiny">Offer days</div><div class="mid blue"><?= (int)$t['o_days'] ?></div></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if ($extras || ($editing && $c['allow_extra_todos'])): ?>
      <h3>Added by the offerer</h3>
      <div id="extra-todos">
      <?php foreach ($extras as $i => $e): ?>
        <?php if ($editing): echo extra_todo_row($i, $e); else: ?>
        <div class="card todo-view"><h4><?= h($e['name']) ?></h4><p class="small"><?= nl2br(h($e['description'])) ?></p>
          <div class="todo-fields"><div><div class="tiny">Offer price</div><div class="mid blue"><?= h(cur_symbol($c)) ?>&nbsp;<?= money($e['price']) ?></div></div><div><div class="tiny">Offer days</div><div class="mid blue"><?= (int)$e['days'] ?></div></div></div></div>
        <?php endif; ?>
      <?php endforeach; ?>
      </div>
      <?php if ($editing && $c['allow_extra_todos']): ?><div class="right"><button type="button" class="btn small blue-outline" id="add-extra">+ Add To Do</button></div><template id="extra-tpl"><?= extra_todo_row(0) ?></template><?php endif; ?>
    <?php endif; ?>
    <?php if ($decidable): ?>
    <div class="row between">
      <button class="btn green" type="button" data-open="accept-dialog">Accept Offer</button>
      <button class="btn red" type="submit" formaction="/offer/<?= (int)$offer['id'] ?>/reject" formnovalidate>Reject Offer</button>
    </div>
    <?php endif; ?>
  </div>
  <div class="col">
    <hr>
    <div class="gallery">
      <?php if (!array_filter($files, fn($f) => $f['kind'] === 'image') && !empty($c['category_image'])): ?><figure><img src="/uploads/<?= h($c['category_image']) ?>" alt=""></figure><?php endif; ?>
      <?php foreach ($files as $f): if ($f['kind'] !== 'image') { continue; } ?><figure><img src="/uploads/<?= h($f['stored_name']) ?>" alt=""></figure><?php endforeach; ?>
    </div>
    <h3>Files</h3>
    <ul class="files"><?php foreach ($files as $f): if ($f['kind'] !== 'file') { continue; } ?><li><a href="/uploads/<?= h($f['stored_name']) ?>" download="<?= h($f['original_name']) ?>"><?= h($f['original_name']) ?></a></li><?php endforeach; ?></ul>
    <hr>
    <div class="sums two">
      <div><div class="lbl">Price</div><div class="big green"><?= money($tot['price']) ?></div><div class="cur"><?= h(cur_code($c)) ?></div></div>
      <div class="center"><div class="lbl">Time days</div><div class="big green"><?= $tot['days'] ?></div></div>
    </div>
    <hr>
    <div class="sums">
      <div><div class="lbl">Offer Price</div>
        <?php if ($editing): ?><input name="price" id="offer-price" value="<?= h((string)(float)$offer['price']) ?>" inputmode="decimal" <?= $hasSteps ? 'readonly' : '' ?>><?php else: ?><div class="big blue"><?= money($offer['price']) ?></div><?php endif; ?>
        <div class="cur"><?= h(cur_code($c)) ?></div></div>
      <div class="center"><div class="lbl">Offer days</div>
        <?php if ($editing): ?><input name="days" id="offer-days" type="number" min="0" value="<?= (int)$offer['days'] ?>" <?= $hasSteps ? 'readonly' : '' ?>><?php else: ?><div class="big blue"><?= (int)$offer['days'] ?></div><?php endif; ?></div>
      <div class="center"><div class="lbl">Offer From</div><a class="blue" href="/user/<?= (int)$offer['user_id'] ?>"><?= h($offer['oname']) ?></a> <?= stars((float)$offer['rating_avg'], (int)$offer['rating_count']) ?></div>
    </div>
    <label class="small">Notes
      <?php if ($editing): ?><textarea name="notes" rows="4"><?= h($offer['notes']) ?></textarea><?php else: ?><div class="note-box"><?= nl2br(h($offer['notes'])) ?></div><?php endif; ?>
    </label>
    <?php if ($editing): ?>
    <div class="row right gap">
      <button class="btn green" type="submit">Save Offer</button>
      <a class="btn" href="/offer/<?= (int)$offer['id'] ?>">Cancel</a>
    </div>
    <?php elseif ($editable): ?>
    <div class="row right gap">
      <a class="btn" href="/offer/<?= (int)$offer['id'] ?>?edit=1">Edit Offer</a>
      <button class="btn red" type="submit" formaction="/offer/<?= (int)$offer['id'] ?>/delete" formnovalidate onclick="return confirm('Delete your offer?')">Delete My Offer</button>
    </div>
    <?php endif; ?>
  </div>
</form>

<?php if ($decidable): ?>
<form id="accept-form" method="post" action="/offer/<?= (int)$offer['id'] ?>/accept"><?= csrf_field() ?></form>
<div class="modal" id="accept-dialog" hidden>
  <div class="modal-card pay-card" role="dialog" aria-modal="true" aria-labelledby="pay-title">
    <!-- TEMPORARY DUMMY CONTENT: the payment window will be replaced later -->
    <div class="pay-art">
      <svg viewBox="0 0 320 190" role="img" aria-label="Payment card illustration">
        <rect x="6" y="6" width="308" height="178" rx="16" fill="#12ad00"/>
        <rect x="6" y="46" width="308" height="34" fill="#0b7d00"/>
        <rect x="26" y="108" width="80" height="26" rx="5" fill="#fff" opacity=".9"/>
        <rect x="26" y="148" width="150" height="10" rx="5" fill="#fff" opacity=".7"/>
        <circle cx="262" cy="146" r="18" fill="#fff" opacity=".85"/><circle cx="240" cy="146" r="18" fill="#fff" opacity=".55"/>
      </svg>
    </div>
    <h2 id="pay-title">Payment</h2>
    <p class="muted small">Demo payment window. No real money is charged.</p>
    <table class="pay-table">
      <tr><td>Contract</td><td><?= h($c['title']) ?></td></tr>
      <tr><td>Contractor</td><td><?= h($offer['oname']) ?></td></tr>
      <tr><td>Offer days</td><td><?= (int)$offer['days'] ?></td></tr>
      <tr><td>Payment method</td><td>Card &bull;&bull;&bull;&bull; 4242</td></tr>
      <tr class="pay-total"><td>Amount</td><td><?= h(cur_symbol($c)) ?>&nbsp;<?= money($offer['price']) ?></td></tr>
    </table>
    <div class="row between pay-actions">
      <button type="submit" form="accept-form" class="btn green" data-close="accept-dialog">Proceed</button>
      <button type="button" class="btn" data-close="accept-dialog">Cancel</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="wrap" id="comments">
  <h3>Comments</h3>
  <?php render_comments($comments, (int)$c['user_id'], true); ?>
  <form method="post" action="/offer/<?= (int)$offer['id'] ?>/comment" class="comment-form" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input name="body" maxlength="4000" placeholder="Write a comment">
    <?= attach_button() ?>
    <button class="btn small" type="submit">Post Comment</button>
  </form>
</div>
<?php }, ['back' => '/contract/' . (int)$c['id']]);
