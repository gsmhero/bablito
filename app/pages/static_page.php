<?php
declare(strict_types=1);

$p = row('SELECT * FROM static_pages WHERE slug = ?', [$args[0]]);
if (!$p) {
    not_found();
}
render($p['title'], function () use ($p) { ?>
<div class="narrow">
  <h1><?= h($p['title']) ?></h1>
  <div class="prose"><?= nl2br(h($p['body'])) ?></div>
</div>
<?php });
