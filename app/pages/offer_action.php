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
        $cm = log_comment((int)$c['id'], 'offer', (int)$offer['id'], null, (string)($_POST['body'] ?? ''));
        if ($cm) {
            notify('offer_comment', $c, ['to' => [$isOwner ? (int)$offer['user_id'] : (int)$c['user_id']], 'url' => $back . '#comments', 'comment' => $cm]);
        }
        redirect($back . '#comments');

    case 'accept':
        if (!$isOwner || $offer['status'] !== 'pending' || $c['status'] !== 'published') {
            forbidden();
        }
        $others = array_column(rows("SELECT user_id FROM offers WHERE contract_id = ? AND id <> ? AND status = 'pending'", [$c['id'], $offer['id']]), 'user_id');
        $pdo = db();
        $pdo->beginTransaction();
        accept_offer_terms($c, $offer);
        $pdo->commit();
        notify('offer_accepted', $c, ['to' => [(int)$offer['user_id']], 'url' => '/contract/' . (int)$c['id']]);
        notify('offer_lost', $c, ['to' => $others, 'url' => '/contracts']);
        flash('Offer accepted. The contract is now active.');
        redirect('/contract/' . $c['id']);

    case 'reject':
        if (!$isOwner || $offer['status'] !== 'pending') {
            forbidden();
        }
        notify('offer_rejected', $c, ['to' => [(int)$offer['user_id']], 'url' => '/contracts']);
        delete_offers('id = ?', [$offer['id']]);
        flash('Offer rejected and deleted.');
        redirect('/contract/' . $c['id']);

    case 'delete':
        if (!$isOfferer || $offer['status'] === 'accepted') {
            forbidden();
        }
        notify('offer_withdrawn', $c, ['to' => [(int)$c['user_id']]]);
        delete_offers('id = ?', [$offer['id']]);
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
        q('UPDATE offers SET seen_at = NULL WHERE id = ?', [$offer['id']]);
        q('UPDATE offers SET price = ?, days = ?, notes = ?, payment_type = ?, offerer_type = ? WHERE id = ?', [$o['price'], $o['days'], $o['notes'], $o['payment_type'], $o['offerer_type'], $offer['id']]);
        save_offer_steps((int)$offer['id'], $o['steps'], $o['extras']);
        $pdo->commit();
        notify('offer_edit', $c, ['to' => [(int)$c['user_id']], 'url' => $back]);
        flash('Offer updated.');
        redirect($back);
}
not_found();
