<?php
declare(strict_types=1);

$u = require_login();
$list = rows('SELECT id, text, url, created_at, read_at FROM notices WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$u['id']]);
// everything shown here counts as read from now on
q('UPDATE notices SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$u['id']]);

render('Notifications', function () use ($list) { ?>
<div class="wrap">
  <h1>Notifications</h1>
  <?php if (!$list): ?><p class="muted">No notifications yet.</p><?php endif; ?>
  <div class="notice-list">
  <?php foreach ($list as $n): ?>
    <a class="notice-item <?= $n['read_at'] === null ? 'unread' : '' ?>" href="<?= h($n['url'] ?: '/dashboard') ?>">
      <span class="notice-text"><?= h($n['text']) ?></span>
      <span class="notice-time"><?= h(fdatetime($n['created_at'])) ?></span>
    </a>
  <?php endforeach; ?>
  </div>
</div>
<?php });
