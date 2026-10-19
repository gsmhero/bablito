<?php
declare(strict_types=1);

$u = require_login();
$todo = row('SELECT * FROM todos WHERE id = ?', [(int)$args[0]]);
if (!$todo) {
    not_found();
}
$c = load_contract((int)$todo['contract_id']);
$isOwner = (int)$c['user_id'] === (int)$u['id'];
$isContractor = (int)($c['contractor_id'] ?? 0) === (int)$u['id'];
$back = '/contract/' . $c['id'];
if (!$isOwner && !$isContractor) {
    forbidden();
}

switch ($args[1]) {
    case 'comment':
        log_comment((int)$c['id'], 'todo', null, (int)$todo['id'], (string)($_POST['body'] ?? ''));
        redirect($back . '#todo-' . $todo['id']);

    case 'done':
        if (!$isContractor || $c['status'] !== 'active' || $todo['accepted_at']) {
            forbidden();
        }
        q('UPDATE todos SET done_at = ' . ($todo['done_at'] ? 'NULL' : 'NOW()') . ' WHERE id = ?', [$todo['id']]);
        redirect($back . '#todo-' . $todo['id']);

    case 'accept':
        if (!$isOwner || $c['status'] !== 'active' || !$todo['done_at']) {
            forbidden();
        }
        q('UPDATE todos SET accepted_at = ' . ($todo['accepted_at'] ? 'NULL' : 'NOW()') . ' WHERE id = ?', [$todo['id']]);
        $left = (int)val('SELECT COUNT(*) FROM todos WHERE contract_id = ? AND accepted_at IS NULL', [$c['id']]);
        if ($left === 0) {
            stop_timer((int)$c['id']);
            q("UPDATE contracts SET status = 'fulfilled' WHERE id = ?", [$c['id']]);
            flash('All steps accepted. The contract is fulfilled. Please leave a review for the contractor.');
        }
        redirect($back . '#todo-' . $todo['id']);
}
not_found();
