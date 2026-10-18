<?php
declare(strict_types=1);

require_once ROOT . '/app/card.php';
$u = require_login();
$list = rows(listing_select() . " JOIN watch_later w ON w.contract_id = c.id AND w.user_id = ? WHERE c.status = 'published' ORDER BY w.created_at DESC", [$u['id']]);

render('Watch later', function () use ($list, $u) { ?>
<div class="wrap">
  <h1>Watch later</h1>
  <?php if (!$list): ?><p class="muted">Nothing saved yet. Use "Watch later" on a contract.</p><?php endif; ?>
  <?php foreach ($list as $c) { listing_card($c, (int)$u['id']); } ?>
</div>
<?php });
