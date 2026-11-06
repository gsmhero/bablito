<?php
declare(strict_types=1);

require_once ROOT . '/app/offers.php';

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

$authorSide = $isOwner || is_admin();
$tot = contract_totals($id);
$offers = $authorSide ? rows('SELECT o.*, COALESCE(NULLIF(u.name,\'\'), CONCAT(\'User #\', u.id)) AS oname, u.rating_avg, u.rating_count FROM offers o JOIN users u ON u.id = o.user_id WHERE o.contract_id = ? ORDER BY o.created_at', [$id]) : [];
$myOffer = ($u && !$isOwner) ? row('SELECT id FROM offers WHERE contract_id = ? AND user_id = ?', [$id, $u['id']]) : null;
$canOffer = $u && !$isOwner && !$myOffer && $c['status'] === 'published';

// A counteroffer that did not pass validation comes back with everything the person typed (the live refresh only looks).
$draft = null;
if ($canOffer && isset($_SESSION['offer_draft'][$id])) {
    $draft = $_SESSION['offer_draft'][$id];
    if (!is_live_request()) {
        unset($_SESSION['offer_draft'][$id]);
    }
}
$items = offer_items($todos, [], $draft);

// Discussion is private: the author sees one thread per user, everyone else only their own thread.
$threads = [];
$threadId = 0;
$comments = [];
if ($authorSide) {
    $threads = rows("SELECT cm.thread_user_id AS uid, COALESCE(NULLIF(u.name,''), CONCAT('User #', u.id)) AS uname, COUNT(*) AS n
        FROM comments cm JOIN users u ON u.id = cm.thread_user_id WHERE cm.contract_id = ? AND cm.scope = 'public' AND cm.thread_user_id IS NOT NULL GROUP BY cm.thread_user_id, uname ORDER BY MAX(cm.id) DESC", [$id]);
    $threadId = (int)($_GET['thread'] ?? ($threads[0]['uid'] ?? 0));
    if ($threadId) {
        $comments = comments_for("cm.contract_id = ? AND cm.scope = 'public' AND cm.thread_user_id = ?", [$id, $threadId]);
    }
} elseif ($u) {
    $comments = comments_for("cm.contract_id = ? AND cm.scope = 'public' AND cm.thread_user_id = ?", [$id, $u['id']]);
}

render($c['title'], function () use ($c, $author, $todos, $files, $tot, $offers, $myOffer, $comments, $threads, $threadId, $canOffer, $isOwner, $authorSide, $u, $items, $draft) { ?>
<div class="wrap two-col<?= $draft ? ' counter-mode' : '' ?>" id="contract-cols">
  <div class="col">
    <?php if ($authorSide): ?><div class="right"><a class="btn small" href="/contract/<?= (int)$c['id'] ?>/edit">Edit Contract</a></div><?php endif; ?>
    <h1><?= h($c['title']) ?></h1>
    <?php if ($c['status'] === 'draft'): ?><span class="pill grey">Draft</span><?php endif; ?>
    <div class="row between">
      <span><a class="green big-link" href="/user/<?= (int)$author['id'] ?>"><?= h(public_name($author)) ?></a><?= party_pill($c['author_type'] ?? null) ?> <?= stars((float)$author['rating_avg'], (int)$author['rating_count']) ?></span>
      <span class="small muted">Creation Date <?= h(fdate($c['created_at'])) ?></span>
    </div>
    <div class="row between">
      <span class="muted big-link"><?= h($c['location']) ?></span>
      <span><?php if ($c['category']): ?><span class="chip"><?= h($c['category']) ?></span><?php endif; ?> <?php if ($c['prepay_required']): ?><span class="pill blue">Prepaid</span><?php endif; ?></span>
    </div>
    <hr>
    <div class="row between"><h3>Description</h3><span class="tiny muted"><?= (int)$c['views'] ?> views<?= $authorSide ? ' · ' . count($offers) . ' offers' : '' ?></span></div>
    <p><?= nl2br(h($c['description'])) ?></p>
    <?= embed_share_html($c, $author, $todos, $files, $tot) ?>
    <hr>
    <h3>Contract</h3>
    <?php if ($canOffer): ?><form method="post" action="/contract/<?= (int)$c['id'] ?>/offer" id="offer-form"><?= csrf_field() ?></form>
      <form method="post" action="/contract/<?= (int)$c['id'] ?>/offer" id="accept-asis"><?= csrf_field() ?><input type="hidden" name="mode" value="accept"></form><?php endif; ?>
    <?= offer_task_list($c, $items, $canOffer ? 'edit' : 'view') ?>
    <?php if ($canOffer): ?>
      <div class="right view-only"><button type="button" class="btn blue-outline" id="start-counter">Make counteroffer</button></div>
      <p class="tiny muted edit-only">Change the price and days of the To Dos, mark the ones you do not need as Unnecessary, move the To Dos up and down, or add your own.</p>
    <?php endif; ?>
  </div>

  <div class="col">
    <hr>
    <h3>Images</h3>
    <div class="gallery" data-live="images">
      <?php if (!array_filter($files, fn($f) => $f['kind'] === 'image') && category_picture($c) !== ''): ?><figure class="cat-fig"><?= category_picture($c) ?></figure><?php endif; ?>
      <?php foreach ($files as $f): if ($f['kind'] !== 'image') { continue; } ?><figure><a href="/uploads/<?= h($f['stored_name']) ?>" target="_blank"><img src="/uploads/<?= h($f['stored_name']) ?>" alt=""></a></figure><?php endforeach; ?>
    </div>
    <h3>Files</h3>
    <ul class="files"><?php foreach ($files as $f): if ($f['kind'] !== 'file') { continue; } ?><li><a href="/uploads/<?= h($f['stored_name']) ?>" download="<?= h($f['original_name']) ?>"><?= h($f['original_name']) ?></a></li><?php endforeach; ?></ul>
    <hr>
    <div class="sums<?= $c['deadline'] ? '' : ' two' ?>">
      <div><div class="lbl">Price</div><div class="big green"><?= money($tot['price']) ?></div><div class="cur"><?= h(cur_code($c)) ?></div></div>
      <div class="center"><div class="lbl">Time days</div><div class="big green"><?= $tot['days'] ?></div></div>
      <?php if ($c['deadline']): ?><div class="center"><div class="lbl">Preferred till date</div><div class="pref-date"><?= h(fdate($c['deadline'])) ?></div></div><?php endif; ?>
    </div>
    <hr>
    <?php if ($canOffer): ?>
      <div class="view-only">
        <button class="btn green" type="button" data-open="accept-asis-dialog">Accept Contract</button>
        <p class="tiny muted">Accept the contract as the author wrote it, or make a counteroffer.</p>
      </div>
      <div class="edit-only">
        <div class="sums two">
          <div><div class="lbl">Offer Price</div><div class="big blue" id="ot-price"><?= money($tot['price']) ?></div><div class="cur"><?= h(cur_code($c)) ?></div></div>
          <div class="center"><div class="lbl">Offer days</div><div class="big blue" id="ot-days"><?= (int)$tot['days'] ?></div></div>
        </div>
        <div class="small"><b>Offer as</b> <?= as_select('offerer_type', user_types($u), $draft['offerer_type'] ?? null, 'offer-form') ?></div>
        <div class="small pay-type"><b>Payment for the contract</b>
          <label class="check"><input type="radio" form="offer-form" name="payment_type" value="finish" <?= ($draft['payment_type'] ?? 'finish') !== 'step' ? 'checked' : '' ?>> When Contract Finished</label>
          <label class="check"><input type="radio" form="offer-form" name="payment_type" value="step" <?= ($draft['payment_type'] ?? '') === 'step' ? 'checked' : '' ?>> Step by Step</label>
        </div>
        <label class="small">Notes <textarea form="offer-form" name="notes" rows="4"><?= h($draft['notes'] ?? '') ?></textarea></label>
        <div class="row gap offer-actions">
          <button form="offer-form" class="btn green" type="submit">Send Counteroffer</button>
          <button class="btn" type="button" id="cancel-counter">Cancel</button>
        </div>
      </div>
    <?php elseif ($myOffer): ?>
      <p><a class="btn" href="/offer/<?= (int)$myOffer['id'] ?>">View my offer</a></p>
    <?php elseif (!$u && $c['status'] === 'published'): ?>
      <p><a href="/login">Log in</a> to make an offer.</p>
    <?php endif; ?>

    <div data-live="offers">
    <?php if ($isOwner && $offers): ?>
      <h3>Offers (<?= count($offers) ?>)</h3>
      <table class="offers">
        <?php foreach ($offers as $o): ?>
          <tr>
            <td><a class="blue" href="/user/<?= (int)$o['user_id'] ?>"><?= h($o['oname']) ?></a><?= party_pill($o['offerer_type'] ?? null) ?> <?= stars((float)$o['rating_avg'], (int)$o['rating_count'], false) ?></td>
            <td><a class="offer-link" href="/offer/<?= (int)$o['id'] ?>"><b class="blue"><?= money($o['price']) ?></b> <?= h(cur_code($c)) ?></a></td>
            <td><a class="offer-link" href="/offer/<?= (int)$o['id'] ?>"><b class="blue"><?= (int)$o['days'] ?></b> days</a></td>
            <td><a class="btn small" href="/offer/<?= (int)$o['id'] ?>"><?= h(ucfirst($o['status'])) ?></a></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    </div>

    <?php if ($u && $c['status'] === 'published' && !$isOwner): ?>
      <?php $wl = (bool)val('SELECT 1 FROM watch_later WHERE user_id = ? AND contract_id = ?', [$u['id'], $c['id']]); ?>
      <form method="post" action="/contract/<?= (int)$c['id'] ?>/watch" class="right"><?= csrf_field() ?><button class="linkbtn watch" type="submit"><?= clock_icon() ?> <span class="wl-text"><?= $wl ? 'Remove from watch later' : 'Watch later' ?></span></button></form>
    <?php endif; ?>

    <?php if ($c['status'] === 'published' && $u): ?>
    <div id="comments">
      <h3>Comments <?php if ($comments): ?><span class="badge"><?= count($comments) ?></span><?php endif; ?></h3>
      <p class="tiny muted">Private: only the author and the other participant can read this conversation.</p>
      <?php if ($authorSide): ?>
        <?php if (!$threads): ?><p class="muted small">No one has written to you yet.</p><?php endif; ?>
        <div class="thread-tabs" data-live="threads">
          <?php foreach ($threads as $th): ?><a class="<?= (int)$th['uid'] === $threadId ? 'on' : '' ?>" href="?thread=<?= (int)$th['uid'] ?>#comments"><?= h($th['uname']) ?> (<?= (int)$th['n'] ?>)</a><?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (!$authorSide || $threadId): ?>
        <div data-live="comments"><?php render_comments($comments, (int)$c['user_id'], true); ?></div>
        <form method="post" action="/contract/<?= (int)$c['id'] ?>/comment" class="comment-form" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <?php if ($authorSide): ?><input type="hidden" name="thread" value="<?= $threadId ?>"><?php endif; ?>
          <input name="body" maxlength="4000" placeholder="<?= $isOwner ? 'Reply' : 'Ask the author' ?>">
          <?= attach_button() ?>
          <button class="btn small" type="submit">Post Comment</button>
        </form>
      <?php endif; ?>
    </div>
    <?php elseif (!$u && $c['status'] === 'published'): ?>
      <p class="small muted"><a href="/login">Log in</a> to write to the author.</p>
    <?php endif; ?>
  </div>
</div>
<?php if ($canOffer): ?>
<div class="modal" id="accept-asis-dialog" hidden>
  <div class="modal-card pay-card" role="dialog" aria-modal="true">
    <h2>Accept Contract</h2>
    <p class="small">You accept the contract as it is: every To Do with the author's price and days (<b><?= h(cur_symbol($c)) ?>&nbsp;<?= money($tot['price']) ?></b>, <?= (int)$tot['days'] ?> days). The author confirms the offer after that.</p>
    <div class="small"><b>Offer as</b> <?= as_select('offerer_type', user_types($u), null, 'accept-asis') ?></div>
    <div class="small pay-type"><b>Payment for the contract</b>
      <label class="check"><input type="radio" form="accept-asis" name="payment_type" value="finish" checked> When Contract Finished</label>
      <label class="check"><input type="radio" form="accept-asis" name="payment_type" value="step"> Step by Step</label>
    </div>
    <label class="small">Notes <textarea form="accept-asis" name="notes" rows="3"></textarea></label>
    <div class="row between pay-actions">
      <button type="submit" form="accept-asis" class="btn green">Accept Contract</button>
      <button type="button" class="btn" data-close="accept-asis-dialog">Cancel</button>
    </div>
  </div>
</div>
<?php endif; ?>
<?php });
