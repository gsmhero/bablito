<?php
declare(strict_types=1);

require_once ROOT . '/app/offers.php';

$u = require_login();
$offer = row('SELECT * FROM offers WHERE id = ?', [(int)$args[0]]);
if (!$offer) {
    not_found();
}
$c = load_contract((int)$offer['contract_id']);
$isOwner = (int)$c['user_id'] === (int)$u['id'];
$isOfferer = (int)$offer['user_id'] === (int)$u['id'];
$back = '/offer/' . $offer['id'];
if (!$isOwner && !$isOfferer && !is_admin()) {
    forbidden();
}

switch ($args[1]) {
    case 'comment':
        log_comment((int)$c['id'], 'offer', (int)$offer['id'], null, (string)($_POST['body'] ?? ''));
        redirect($back . '#comments');

    case 'accept':
        if (!$isOwner || $offer['status'] !== 'pending' || $c['status'] !== 'published') {
            forbidden();
        }
        $pdo = db();
        $pdo->beginTransaction();
        accept_offer_terms($c, $offer);
        $pdo->commit();
        flash('Offer accepted. The contract is now active.');
        redirect('/contract/' . $c['id']);

    case 'reject':
        if (!$isOwner || $offer['status'] !== 'pending') {
            forbidden();
        }
        q("UPDATE offers SET status = 'rejected' WHERE id = ?", [$offer['id']]);
        flash('Offer rejected.');
        redirect('/contract/' . $c['id']);

    case 'delete':
        if (!$isOfferer || $offer['status'] === 'accepted') {
            forbidden();
        }
        q('DELETE FROM offers WHERE id = ?', [$offer['id']]);
        flash('Your offer was deleted.');
        redirect('/contract/' . $c['id']);

    case 'edit':
        if (!$isOfferer || $offer['status'] !== 'pending' || $c['status'] !== 'published') {
            forbidden();
        }
        $todos = rows('SELECT id FROM todos WHERE contract_id = ?', [$c['id']]);
        $o = parse_offer_input($todos, (bool)$c['allow_extra_todos']);
        if (is_string($o)) {
            flash($o, 'err');
            redirect($back);
        }
        $pdo = db();
        $pdo->beginTransaction();
        q('UPDATE offers SET price = ?, days = ?, notes = ? WHERE id = ?', [$o['price'], $o['days'], $o['notes'], $offer['id']]);
        save_offer_steps((int)$offer['id'], $o['steps'], $o['extras']);
        $pdo->commit();
        flash('Offer updated.');
        redirect($back);
}
not_found();
