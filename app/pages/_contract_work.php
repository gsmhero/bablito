<?php
declare(strict_types=1);

// Included by contract_view.php for accepted contracts (active / paused / fulfilled / terminated).
if (!$u || (!$isOwner && !$isContractor && !is_admin())) {
    forbidden();
}
$offer = row('SELECT * FROM offers WHERE id = ?', [$c['accepted_offer_id']]);
$comments = comments_for("cm.contract_id = ? AND cm.scope = 'work'", [$id]);
$todoComments = [];
foreach (comments_for("cm.contract_id = ? AND cm.scope = 'todo'", [$id]) as $cm) {
    $todoComments[(int)$cm['todo_id']][] = $cm;
}
$accepted = 0;
$acceptedSum = 0.0;
$deadline = new DateTimeImmutable($c['started_at'] ?: 'now');
$cum = 0;
foreach ($todos as $i => $t) {
    $cum += (int)($t['agreed_days'] ?? $t['days']);
    $todos[$i]['deadline'] = $deadline->modify("+$cum days")->format('Y-m-d');
    if ($t['accepted_at']) {
        $accepted++;
        $acceptedSum += (float)$t['agreed_price'];
    }
}
$n = count($todos);
$overdue = is_overdue($c);
$tillDate = $c['agreed_deadline'];
$active = $c['status'] === 'active';
$canUpload = in_array($c['status'], ['active', 'paused'], true) && ($isOwner || $isContractor);
$myReview = ($c['status'] === 'fulfilled' && $u) ? row('SELECT id FROM reviews WHERE contract_id = ? AND reviewer_id = ?', [$id, $u['id']]) : null;
$other = $isOwner ? $contractor : $author;
if ($u && ($isOwner || $isContractor)) {
    $maxC = (int)val("SELECT COALESCE(MAX(id), 0) FROM comments WHERE contract_id = ? AND scope IN ('work','todo')", [$id]);
    q('REPLACE INTO comment_reads (user_id, contract_id, last_id) VALUES (?,?,?)', [$u['id'], $id, $maxC]);
}
$timer = row('SELECT timer_seconds + IF(timer_started_at IS NULL, 0, TIMESTAMPDIFF(SECOND, timer_started_at, NOW())) AS s, timer_started_at IS NOT NULL AS running FROM contracts WHERE id = ?', [$id]);

render($c['title'], function () use ($c, $author, $contractor, $todos, $files, $offer, $comments, $todoComments, $accepted, $acceptedSum, $n, $overdue, $tillDate, $active, $canUpload, $isOwner, $isContractor, $myReview, $other, $u, $timer) { ?>
<div class="wrap two-col">
  <div class="col">
    <h1><?= h($c['title']) ?></h1>
    <?php [$lbl, $cls] = status_label($c['status']); ?>
    <div class="pills">
    <span class="pill <?= h($overdue ? 'red' : $cls) ?>"><?= h($overdue ? 'Overdue' : $lbl) ?></span>
    <?php if ($c['prepay_required']): ?><span class="pill blue"><?= $c['prepaid_at'] ? 'Prepayd' : 'Prepayment pending' ?></span><?php endif; ?>
    </div>
    <div class="row between">
      <span class="small">Contractor:<br><?php if ($contractor): ?><a class="blue big-link" href="/user/<?= (int)$contractor['id'] ?>"><?= h(public_name($contractor)) ?></a> <?= stars((float)$contractor['rating_avg'], (int)$contractor['rating_count']) ?><?php endif; ?></span>
      <span class="small muted">Creation Date <?= h(fdate($c['created_at'])) ?> <?php if ($c['category']): ?><span class="chip"><?= h($c['category']) ?></span><?php endif; ?></span>
    </div>
    <div class="row between client-row"><div class="small">Client:<br><a class="green big-link" href="/user/<?= (int)$author['id'] ?>"><?= h(public_name($author)) ?></a></div><?php if (($rate = hour_rate($c)) !== null): ?><div class="rate" title="Agreed price / (agreed days x 8 hours)"><?= h(cur_symbol($c)) ?> <?= h(money($rate)) ?>/ hour</div><?php endif; ?></div>
    <hr>
    <h3>Description</h3>
    <p><?= nl2br(h($c['description'])) ?></p>
    <hr>
    <h3>Contract</h3>
    <?php foreach ($todos as $t):
        $done = (bool)$t['done_at'];
        $acc = (bool)$t['accepted_at']; ?>
      <div class="card todo-work <?= $acc ? 'is-accepted' : '' ?>" id="todo-<?= (int)$t['id'] ?>">
        <h4><?= h($t['name']) ?></h4>
        <p class="small"><?= nl2br(h($t['description'])) ?></p>
        <div class="row between">
          <div><div class="tiny">Price</div><div class="mid <?= $acc ? 'grey' : 'blue' ?>"><?= h(cur_symbol($c)) ?>&nbsp;<?= money($t['agreed_price']) ?></div></div>
          <div><div class="tiny">Deadline</div><div class="mid <?= (!$acc && $t['deadline'] < date('Y-m-d')) ? 'red' : 'blue' ?>"><?= h(fdate($t['deadline'])) ?></div></div>
          <div>
            <div class="tiny">Mark as Done</div>
            <form method="post" action="/todo/<?= (int)$t['id'] ?>/done" class="inline"><?= csrf_field() ?>
              <label class="check"><input type="checkbox" <?= $done ? 'checked' : '' ?> <?= ($isContractor && $active && !$acc) ? 'onchange="this.form.submit()"' : 'disabled' ?>> Contractor Done</label>
            </form>
            <form method="post" action="/todo/<?= (int)$t['id'] ?>/accept" class="inline"><?= csrf_field() ?>
              <label class="check"><input type="checkbox" class="accept-box" <?= $acc ? 'checked' : '' ?> <?= ($isOwner && $active && $done) ? 'onchange="this.form.submit()"' : 'disabled' ?>> Accepted</label>
            </form>
          </div>
        </div>
        <div class="tiny muted">Comments</div>
        <div class="comment-box">
          <?php render_comments($todoComments[(int)$t['id']] ?? [], (int)$c['user_id'], true); ?>
          <form method="post" action="/todo/<?= (int)$t['id'] ?>/comment" class="comment-form" enctype="multipart/form-data"><?= csrf_field() ?>
            <input name="body" maxlength="4000" placeholder="Comment on this step">
            <?= attach_button() ?>
            <button class="btn small" type="submit">Post Comment</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if ($isOwner && in_array($c['status'], ['active', 'paused'], true)): ?>
      <div class="row between">
        <form method="post" action="/contract/<?= (int)$c['id'] ?>/pause"><?= csrf_field() ?><button class="btn green" type="submit"><?= $c['status'] === 'paused' ? 'Resume Contract' : 'Pause Contract' ?></button></form>
        <form method="post" action="/contract/<?= (int)$c['id'] ?>/fire" onsubmit="return confirm('Dismiss the contractor and archive this contract?')"><?= csrf_field() ?><button class="btn <?= $overdue ? 'red' : 'red-outline' ?>" type="submit">Fire Contractor</button></form>
      </div>
    <?php endif; ?>
  </div>

  <div class="col">
    <?php $ts = (int)$timer['s']; $running = $timer['running'] && $active; ?>
    <div class="row end gap sw-row">
      <span class="tiny muted">Stopwatch</span>
      <span class="run-state <?= $running ? 'on' : '' ?>" id="run-state"><?= $running ? 'Started' : 'Stopped' ?></span>
      <span class="stopwatch" id="stopwatch" data-poll="/contract/<?= (int)$c['id'] ?>/timer.json" data-seconds="<?= $ts ?>" data-running="<?= $running ? 1 : 0 ?>"><?= h(sprintf('%02d:%02d:%02d:%02d', intdiv($ts, 86400), intdiv($ts % 86400, 3600), intdiv($ts % 3600, 60), $ts % 60)) ?></span>
      <?php if ($isContractor && $active): ?>
        <form method="post" action="/contract/<?= (int)$c['id'] ?>/timer" class="inline"><?= csrf_field() ?><button class="btn small <?= $timer['running'] ? 'red' : 'green' ?>" type="submit"><?= $timer['running'] ? 'Stop' : 'Start' ?></button></form>
      <?php endif; ?>
      <a class="btn small" href="/contract/<?= (int)$c['id'] ?>/timelog" data-modal>Log</a>
    </div>
    <hr>
    <?php if ($canUpload): ?>
      <div class="row gap">
        <form method="post" action="/contract/<?= (int)$c['id'] ?>/upload" enctype="multipart/form-data" class="inline"><?= csrf_field() ?><input type="hidden" name="kind" value="image">
          <label class="btn small file-btn">Upload Image <input type="file" name="upload" accept="image/*" hidden onchange="this.form.submit()"></label></form>
      </div>
    <?php endif; ?>
    <div class="gallery">
      <?php foreach ($files as $f): if ($f['kind'] !== 'image') { continue; } ?><figure class="<?= $f['by_contractor'] ? 'by-contractor' : '' ?>"><a href="/uploads/<?= h($f['stored_name']) ?>" target="_blank"><img src="/uploads/<?= h($f['stored_name']) ?>" alt=""></a></figure><?php endforeach; ?>
    </div>
    <hr>
    <h3>Files</h3>
    <?php if ($canUpload): ?>
      <form method="post" action="/contract/<?= (int)$c['id'] ?>/upload" enctype="multipart/form-data" class="inline"><?= csrf_field() ?><input type="hidden" name="kind" value="file">
        <label class="btn small file-btn">Upload File <input type="file" name="upload" hidden onchange="this.form.submit()"></label></form>
    <?php endif; ?>
    <ul class="files"><?php foreach ($files as $f): if ($f['kind'] !== 'file') { continue; } ?><li class="<?= $f['by_contractor'] ? 'by-contractor' : '' ?>"><a href="/uploads/<?= h($f['stored_name']) ?>" download="<?= h($f['original_name']) ?>"><?= h($f['original_name']) ?></a></li><?php endforeach; ?></ul>
    <hr>
    <div class="sums">
      <div><div class="lbl">Price</div><div class="big blue"><?= money($c['agreed_price']) ?></div><div class="cur"><?= h(cur_code($c)) ?></div></div>
      <div class="center"><div class="lbl">Time days</div><div class="big blue"><?= (int)$c['agreed_days'] ?></div></div>
      <div class="center"><div class="lbl">Till Date</div><div class="big <?= $overdue ? 'red' : 'blue' ?> sm"><?= h(fdate($tillDate)) ?></div><?php if ($overdue): ?><span class="pill red">Overdue</span><?php endif; ?></div>
    </div>
    <div class="progress"><span style="width:<?= $n ? round($accepted / $n * 100) : 0 ?>%"></span></div>
    <div class="row between blue small"><b><?= $accepted ?>/<?= $n ?></b><span><b><?= money($acceptedSum) ?></b> <?= h(cur_code($c)) ?> accepted</span></div>
    <?php if ($offer && $offer['notes']): ?><div class="small"><b>Notes</b><div class="note-box"><?= nl2br(h($offer['notes'])) ?></div></div><?php endif; ?>

    <?php if ($c['prepay_required']): ?>
      <div class="row gap">
        <?php if ($isOwner && !$c['prepaid_at'] && in_array($c['status'], ['active', 'paused'], true)): ?>
          <form method="post" action="/contract/<?= (int)$c['id'] ?>/prepay" onsubmit="return confirm('Mark the prepayment as paid? Money is settled directly between you and the contractor.')"><?= csrf_field() ?><button class="btn blue-outline" type="submit">Prepay Contract</button></form>
        <?php endif; ?>
        <?php if ($c['prepaid_at']): ?><span class="btn blue-solid">Prepayd</span><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($c['status'] === 'fulfilled' && $other && !$myReview): ?>
      <p class="flash ok">Contract fulfilled. <a href="/user/<?= (int)$other['id'] ?>#add-review">Leave a review for <?= h(public_name($other)) ?></a>.</p>
    <?php endif; ?>

    <div id="comments">
      <h3>Comments</h3>
      <?php render_comments($comments, (int)$c['user_id'], true); ?>
      <?php if ($isOwner || $isContractor): ?>
      <form method="post" action="/contract/<?= (int)$c['id'] ?>/comment" class="comment-form" enctype="multipart/form-data"><?= csrf_field() ?>
        <input name="body" maxlength="4000" placeholder="Write a comment">
        <?= attach_button() ?>
        <button class="btn small" type="submit">Post Comment</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php });
