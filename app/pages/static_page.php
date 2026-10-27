<?php
declare(strict_types=1);

$p = row('SELECT * FROM static_pages WHERE slug = ?', [$args[0]]);
if (!$p) {
    not_found();
}
// Russian version when the site is in Russian and a Russian text exists
$ru = current_lang() === 'ru';
$title = ($ru && (string)$p['title_ru'] !== '') ? (string)$p['title_ru'] : (string)$p['title'];
$body = ($ru && (string)$p['body_ru'] !== '') ? (string)$p['body_ru'] : (string)$p['body'];
$body = str_replace('{price}', service_price_text($ru ? 'ru' : 'en'), $body);
// plain text with line breaks; e-mail addresses become links
$html = nl2br(h($body));
$html = (string)preg_replace('/([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', '<a href="mailto:$1">$1</a>', $html);

render($title, function () use ($title, $html) { ?>
<div class="narrow">
  <h1><?= h($title) ?></h1>
  <div class="prose"><?= $html ?></div>
</div>
<?php });
