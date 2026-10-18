<?php
declare(strict_types=1);

$p = row('SELECT * FROM users WHERE id = ? AND blocked = 0', [(int)$args[0]]);
if (!$p || $p['pass_hash'] === '' && $p['role'] !== 'admin') {
    not_found();
}
$me = user();
$self = $me && (int)$me['id'] === (int)$p['id'];
$see = $self || is_admin();
$extra = json_decode((string)$p['extra_fields'], true);
$extra = is_array($extra) ? $extra : [];
$reviews = rows('SELECT r.*, COALESCE(NULLIF(u.name,\'\'), CONCAT(\'User #\', u.id)) AS rname FROM reviews r JOIN users u ON u.id = r.reviewer_id WHERE r.reviewed_id = ? ORDER BY r.id DESC LIMIT 100', [$p['id']]);
$eligible = null;
if ($me && !$self) {
    $eligible = row("SELECT c.id, c.title FROM contracts c WHERE c.status = 'fulfilled'
        AND ((c.user_id = :a AND c.contractor_id = :b) OR (c.user_id = :b AND c.contractor_id = :a))
        AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.contract_id = c.id AND r.reviewer_id = :a) ORDER BY c.id DESC LIMIT 1", ['a' => $me['id'], 'b' => $p['id']]);
}

render(public_name($p), function () use ($p, $see, $extra, $reviews, $eligible, $me, $self) { ?>
<div class="narrow profile">
  <h1 class="green"><?= h(public_name($p)) ?></h1>
  <div><?= stars((float)$p['rating_avg'], (int)$p['rating_count']) ?></div>
  <?php if ($p['contact_phone'] && ($p['show_phone'] || $see)): ?><div class="small">Phone &nbsp;<?= h($p['contact_phone']) ?></div><?php endif; ?>
  <?php if ($p['contact_email'] && ($p['show_email'] || $see)): ?><div class="small">Email &nbsp;<?= h($p['contact_email']) ?></div><?php endif; ?>
  <?php foreach ($extra as $e): if (!empty($e['public']) || $see): ?><div class="small"><?= h($e['label']) ?> &nbsp;<?= h($e['value']) ?></div><?php endif; endforeach; ?>
  <?php if ($p['about']): ?><div class="note-box"><?= nl2br(h($p['about'])) ?></div><?php endif; ?>
  <?php if ($self): ?><p><a class="btn small" href="/profile">Edit profile</a></p><?php endif; ?>

  <div id="add-review">
  <?php if ($eligible): ?>
    <form method="post" action="/user/<?= (int)$p['id'] ?>/review" class="review-form">
      <?= csrf_field() ?>
      <input type="hidden" name="contract_id" value="<?= (int)$eligible['id'] ?>">
      <div class="row between"><span class="star-input">
        <?php for ($i = 5; $i >= 1; $i--): ?><input type="radio" id="st<?= $i ?>" name="rating" value="<?= $i ?>" required><label for="st<?= $i ?>" title="<?= $i ?>">&#9733;</label><?php endfor; ?>
      </span><button class="btn small green-outline" type="submit">Add Review</button></div>
      <div class="tiny muted">For contract: <?= h($eligible['title']) ?></div>
      <textarea name="body" rows="3" maxlength="2000"></textarea>
    </form>
  <?php elseif ($me && !$self): ?>
    <p class="tiny muted">You can review this user after a fulfilled contract together.</p>
  <?php endif; ?>
  </div>

  <?php foreach ($reviews as $r): ?>
    <div class="review"><b><?= h($r['rname']) ?></b> <?= stars((float)$r['rating'], 0, false) ?><div class="note-box"><?= nl2br(h($r['body'])) ?></div></div>
  <?php endforeach; ?>
</div>
<?php });
