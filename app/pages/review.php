<?php
declare(strict_types=1);

$u = require_login();
$target = (int)$args[0];
$cid = (int)($_POST['contract_id'] ?? 0);
$rating = (int)($_POST['rating'] ?? 0);
$c = row("SELECT * FROM contracts WHERE id = ? AND status = 'fulfilled'", [$cid]);
$pair = $c && (((int)$c['user_id'] === (int)$u['id'] && (int)$c['contractor_id'] === $target) || ((int)$c['user_id'] === $target && (int)$c['contractor_id'] === (int)$u['id']));
if (!$pair || $target === (int)$u['id'] || $rating < 1 || $rating > 5) {
    flash('You cannot leave this review.', 'err');
    redirect('/user/' . $target);
}
if (val('SELECT id FROM reviews WHERE contract_id = ? AND reviewer_id = ?', [$cid, $u['id']])) {
    flash('You have already reviewed this contract.', 'err');
    redirect('/user/' . $target);
}
q('INSERT INTO reviews (contract_id, reviewer_id, reviewed_id, rating, body) VALUES (?,?,?,?,?)', [$cid, $u['id'], $target, $rating, mb_substr(trim((string)($_POST['body'] ?? '')), 0, 2000)]);
recompute_rating($target);
flash('Review added.');
redirect('/user/' . $target);
