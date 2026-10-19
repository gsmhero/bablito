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

render($c['title'], function () use ($c, $author, $todos, $files, $tot, $offers, $myOffer, $comments, $threads, $threadId, $canOffer, $isOwner, $authorSide, $u) { ?>
<div class="wrap two-col">
  <div class="col">
    <?php if ($authorSide): ?><div class="right"><a class="btn small" href="/contract/<?= (int)$c['id'] ?>/edit">Edit Contract</a></div><?php endif; ?>
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
    <div class="row between"><h3>Description</h3><span class="tiny muted"><?= (int)$c['views'] ?> views<?= $authorSide ? ' · ' . count($offers) . ' offers' : '' ?></span></div>
    <p><?= nl2br(h($c['description'])) ?></p>
    <hr>
    <h3>Contract</h3>
    <?php if ($canOffer): ?><form method="post" action="/contract/<?= (int)$c['id'] ?>/offer" id="offer-form"><?= csrf_field() ?></form><?php endif; ?>
    <?php foreach ($todos as $t): ?>
      <div class="card todo-view">
        <h4><?= h($t['name']) ?></h4>
        <p class="small"><?= nl2br(h($t['description'])) ?></p>
        <div class="todo-fields">
          <div><div class="tiny">Price</div><div class="mid green"><?= money($t['price']) ?></div></div>
          <div><div class="tiny">Days</div><div class="mid grey"><?= (int)$t['days'] ?></div></div>
          <?php if ($canOffer): ?>
            <label class="tiny">Offer price <input form="offer-form" name="otodo[<?= (int)$t['id'] ?>][price]" class="num o-price" inputmode="decimal"></label>
            <label class="tiny">Offer Days <input form="offer-form" name="otodo[<?= (int)$t['id'] ?>][days]" class="num o-days" type="number" min="0"></label>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if ($canOffer && $c['allow_extra_todos']): ?>
      <div id="extra-todos"></div>
      <div class="right"><button type="button" class="btn small blue-outline" id="add-extra">+ Add To Do</button></div>
      <p class="tiny muted">The author allows you to add your own To Dos to the contract.</p>
      <template id="extra-tpl"><?= extra_todo_row(0) ?></template>
    <?php endif; ?>
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
      <div class="offer-row">
        <label class="tiny">Price <input form="offer-form" name="price" id="offer-price" class="num" inputmode="decimal"></label>
        <label class="tiny">Time days <input form="offer-form" name="days" id="offer-days" class="num" type="number" min="0"></label>
        <button form="offer-form" class="btn green-outline" type="submit">Make Offer</button>
      </div>
      <label class="small">Notes <textarea form="offer-form" name="notes" rows="4"></textarea></label>
      <p class="tiny muted">Either fill an offer price and days for every To Do, or enter one total price and days. 0 days means the same day.</p>
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
            <td><a class="offer-link" href="/offer/<?= (int)$o['id'] ?>"><b class="blue"><?= money($o['price']) ?></b> RUB</a></td>
            <td><a class="offer-link" href="/offer/<?= (int)$o['id'] ?>"><b class="blue"><?= (int)$o['days'] ?></b> days</a></td>
            <td><a class="btn small" href="/offer/<?= (int)$o['id'] ?>"><?= h(ucfirst($o['status'])) ?></a></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

    <?php if ($u && $c['status'] === 'published' && !$isOwner): ?>
      <form method="post" action="/contract/<?= (int)$c['id'] ?>/watch" class="right"><?= csrf_field() ?><button class="linkbtn watch" type="submit"><?= clock_icon() ?> Watch later</button></form>
    <?php endif; ?>

    <?php if ($c['status'] === 'published' && $u): ?>
    <div id="comments">
      <h3>Comments <?php if ($comments): ?><span class="badge"><?= count($comments) ?></span><?php endif; ?></h3>
      <p class="tiny muted">Private: only the author and the other participant can read this conversation.</p>
      <?php if ($authorSide): ?>
        <?php if (!$threads): ?><p class="muted small">No one has written to you yet.</p><?php endif; ?>
        <div class="thread-tabs">
          <?php foreach ($threads as $th): ?><a class="<?= (int)$th['uid'] === $threadId ? 'on' : '' ?>" href="?thread=<?= (int)$th['uid'] ?>#comments"><?= h($th['uname']) ?> (<?= (int)$th['n'] ?>)</a><?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (!$authorSide || $threadId): ?>
        <?php render_comments($comments, (int)$c['user_id'], true); ?>
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
<?php });
