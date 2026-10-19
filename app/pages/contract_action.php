<?php
declare(strict_types=1);

require_once ROOT . '/app/offers.php';

$u = require_login();
$id = (int)$args[0];
$action = $args[1];
$c = load_contract($id);
$isOwner = (int)$c['user_id'] === (int)$u['id'];
$isContractor = (int)($c['contractor_id'] ?? 0) === (int)$u['id'];
$back = '/contract/' . $id;

switch ($action) {
    case 'watch':
        if ($c['status'] !== 'published') {
            forbidden();
        }
        if (val('SELECT 1 FROM watch_later WHERE user_id = ? AND contract_id = ?', [$u['id'], $id])) {
            q('DELETE FROM watch_later WHERE user_id = ? AND contract_id = ?', [$u['id'], $id]);
        } else {
            q('INSERT INTO watch_later (user_id, contract_id) VALUES (?, ?)', [$u['id'], $id]);
        }
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        redirect(safe_local_path($ref !== '' ? (string)parse_url($ref, PHP_URL_PATH) : $back));

    case 'offer':
        if ($isOwner || $c['status'] !== 'published') {
            flash('You cannot make an offer on this contract.', 'err');
            redirect($back);
        }
        if (val('SELECT id FROM offers WHERE contract_id = ? AND user_id = ?', [$id, $u['id']])) {
            flash('You have already made an offer.', 'err');
            redirect($back);
        }
        $todos = rows('SELECT id FROM todos WHERE contract_id = ?', [$id]);
        $o = parse_offer_input($todos, (bool)$c['allow_extra_todos']);
        if (is_string($o)) {
            flash($o, 'err');
            redirect($back);
        }
        $pdo = db();
        $pdo->beginTransaction();
        q('INSERT INTO offers (contract_id, user_id, price, days, notes) VALUES (?,?,?,?,?)', [$id, $u['id'], $o['price'], $o['days'], $o['notes']]);
        $oid = (int)$pdo->lastInsertId();
        save_offer_steps($oid, $o['steps'], $o['extras']);
        $pdo->commit();
        flash('Offer sent.');
        redirect('/offer/' . $oid);

    case 'comment':
        $body = (string)($_POST['body'] ?? '');
        if ($c['status'] === 'published') {
            // Private thread between the author and one other user; nobody else can read it.
            $thread = $isOwner ? (int)($_POST['thread'] ?? 0) : (int)$u['id'];
            if ($thread <= 0 || $thread === (int)$c['user_id']) {
                forbidden();
            }
            log_comment($id, 'public', null, null, $body, $thread);
            redirect($back . ($isOwner ? '?thread=' . $thread : '') . '#comments');
        } elseif (is_participant($c)) {
            log_comment($id, 'work', null, null, $body);
        } else {
            forbidden();
        }
        redirect($back . '#comments');

    case 'upload':
        if (!is_participant($c) || !in_array($c['status'], ['active', 'paused'], true)) {
            forbidden();
        }
        $kind = ($_POST['kind'] ?? '') === 'image' ? 'image' : 'file';
        $res = store_upload($_FILES['upload'] ?? ['error' => UPLOAD_ERR_NO_FILE], $kind);
        if (is_string($res)) {
            flash($res, 'err');
        } else {
            q('INSERT INTO files (contract_id, user_id, kind, stored_name, original_name, by_contractor) VALUES (?,?,?,?,?,?)', [$id, $u['id'], $res[0], $res[1], $res[2], $isContractor ? 1 : 0]);
            flash('Uploaded.');
        }
        redirect($back);

    case 'pause':
        if (!$isOwner) {
            forbidden();
        }
        if ($c['status'] === 'active') {
            stop_timer($id);
            q("UPDATE contracts SET status = 'paused' WHERE id = ?", [$id]);
            flash('Contract paused.');
        } elseif ($c['status'] === 'paused') {
            q("UPDATE contracts SET status = 'active' WHERE id = ?", [$id]);
            flash('Contract resumed.');
        }
        redirect($back);

    case 'timer':
        if (!$isContractor || $c['status'] !== 'active') {
            forbidden();
        }
        if ($c['timer_started_at']) {
            stop_timer($id);
        } else {
            q('UPDATE contracts SET timer_started_at = NOW() WHERE id = ?', [$id]);
        }
        redirect($back);

    case 'fire':
        if (!$isOwner || !in_array($c['status'], ['active', 'paused'], true)) {
            forbidden();
        }
        q("UPDATE contracts SET status = 'terminated' WHERE id = ?", [$id]);
        stop_timer($id);
        flash('The contractor was dismissed and the contract moved to the archive.');
        redirect('/my');

    case 'prepay':
        if (!$isOwner || !$c['prepay_required'] || $c['prepaid_at'] || !in_array($c['status'], ['active', 'paused'], true)) {
            forbidden();
        }
        q('UPDATE contracts SET prepaid_at = NOW() WHERE id = ?', [$id]);
        flash('Prepayment marked as paid. Payments are settled directly between you and the contractor.');
        redirect($back);

    case 'delete':
        if (!$isOwner && !is_admin()) {
            forbidden();
        }
        if (in_array($c['status'], ['active', 'paused'], true)) {
            flash('An active contract cannot be deleted. Dismiss the contractor first.', 'err');
            redirect($back);
        }
        foreach (rows('SELECT stored_name FROM files WHERE contract_id = ?', [$id]) as $f) {
            @unlink(ROOT . '/uploads/' . $f['stored_name']);
        }
        q('DELETE FROM contracts WHERE id = ?', [$id]);
        flash('Contract deleted.');
        redirect('/my');
}
not_found();
