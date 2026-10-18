<?php
declare(strict_types=1);

$title = setting('home_title', 'Control Your Missions and Money');
$blocks = rows('SELECT * FROM home_blocks ORDER BY position, id');

function contract_art(string $color, int $tilt): string
{
    $fill = $color === 'blue' ? '#2288cc' : '#00bc1f';
    $rot = $tilt;
    return '<svg viewBox="0 0 430 330" class="art" role="img" aria-label="Contract illustration"><rect x="0" y="22" width="430" height="308" rx="22" fill="' . $fill . '"/>'
        . '<g transform="rotate(' . $rot . ' 215 165)"><rect x="40" y="20" width="350" height="290" rx="22" fill="#fff" stroke="#222" stroke-width="2"/>'
        . '<text x="70" y="75" font-family="Fira Sans, sans-serif" font-size="30" font-weight="500" fill="#222">Contract</text>'
        . '<rect x="70" y="95" width="230" height="38" rx="9" fill="#fff" stroke="#222" stroke-width="2"/>'
        . '<rect x="70" y="150" width="230" height="38" rx="9" fill="#fff" stroke="#222" stroke-width="2"/>'
        . '<rect x="70" y="205" width="230" height="38" rx="9" fill="#fff" stroke="#222" stroke-width="2"/>'
        . '<rect x="70" y="260" width="230" height="30" rx="9" fill="#fff" stroke="#222" stroke-width="2"/>'
        . '<rect x="318" y="95" width="42" height="38" rx="8" fill="#fff" stroke="#222" stroke-width="2"/><path d="M327 114l9 9 16-20" stroke="#111" stroke-width="6" fill="none"/>'
        . '<rect x="318" y="150" width="42" height="38" rx="8" fill="#fff" stroke="#222" stroke-width="2"/><path d="M327 169l9 9 16-20" stroke="#111" stroke-width="6" fill="none"/>'
        . '<rect x="318" y="205" width="42" height="38" rx="8" fill="#fff" stroke="#222" stroke-width="2"/></g></svg>';
}

render('Home', function () use ($title, $blocks) { ?>
<section class="wrap hero"><h1><?= h($title) ?></h1></section>
<?php if (is_admin()): ?><div class="wrap"><a class="btn small" href="/admin/home">Edit Homepage</a></div><?php endif; ?>
<?php foreach ($blocks as $i => $b): ?>
  <section class="wrap block <?= $i % 2 ? 'rev' : '' ?>">
    <div class="block-art"><?= contract_art($b['color'], $i % 2 ? 6 : -10) ?></div>
    <div class="block-text">
      <h2 class="<?= $i % 2 ? 'dim' : '' ?>"><?= h($b['title']) ?></h2>
      <p><?= nl2br(h($b['body'])) ?></p>
    </div>
  </section>
<?php endforeach; ?>
<section class="wrap cta">
  <a class="btn green big" href="/contracts">Browse contracts</a>
  <a class="btn big" href="/contract/new">Create contract</a>
</section>
<?php });
