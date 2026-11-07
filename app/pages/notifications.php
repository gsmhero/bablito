<?php
declare(strict_types=1);

$u = require_login();
$list = rows('SELECT id, text, url, created_at, read_at FROM notices WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$u['id']]);
// everything shown here counts as read from now on
q('UPDATE notices SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$u['id']]);

render('Notifications', function () use ($list) { ?>
<div class="wrap">
  <div class="row between"><h1>Notifications</h1>
    <?php if ($list): ?><form method="post" action="/notifications/clear" onsubmit="return confirm(T('Clear all notifications?'))"><?= csrf_field() ?><button class="btn small" type="submit">Clear all</button></form><?php endif; ?></div>
  <?php if (!$list): ?><p class="muted">No notifications yet.</p><?php endif; ?>
  <div class="notice-list">
  <?php foreach ($list as $n): ?>
    <div class="notice-row">
      <a class="notice-item <?= $n['read_at'] === null ? 'unread' : '' ?>" href="<?= h($n['url'] ?: '/dashboard') ?>">
        <span class="notice-text"><?= h($n['text']) ?></span>
        <span class="notice-time"><?= h(fdatetime($n['created_at'])) ?></span>
      </a>
      <form method="post" action="/notifications/clear"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="notice-x" type="submit" title="Clear" aria-label="Clear">&times;</button></form>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php });
