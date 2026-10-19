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
$isStep = ($c['payment_type'] ?? 'finish') === 'step';

switch ($args[1]) {
    case 'comment':
        log_comment((int)$c['id'], 'todo', null, (int)$todo['id'], (string)($_POST['body'] ?? ''));
        redirect($back . '#todo-' . $todo['id']);

    case 'done':
        if (!$isContractor || $c['status'] !== 'active' || $todo['accepted_at']) {
            forbidden();
        }
        if (!$todo['done_at'] && $isStep && has_unpaid_invoice((int)$c['id'])) {
            flash('Step-by-step contract: the next step continues after the previous step is paid.', 'err');
            redirect($back . '#todo-' . $todo['id']);
        }
        q('UPDATE todos SET done_at = ' . ($todo['done_at'] ? 'NULL' : 'NOW()') . ' WHERE id = ?', [$todo['id']]);
        redirect($back . '#todo-' . $todo['id']);

    case 'accept':
        if (!$isOwner || $c['status'] !== 'active' || !$todo['done_at']) {
            forbidden();
        }
        if ($todo['accepted_at']) {
            // taking the acceptance back is possible only while its invoice is still unpaid and untouched
            $inv = row('SELECT id, status FROM invoices WHERE todo_id = ?', [$todo['id']]);
            if ($inv && $inv['status'] !== 'waiting') {
                flash('This step is already being paid, so the acceptance cannot be withdrawn.', 'err');
                redirect($back . '#todo-' . $todo['id']);
            }
            if ($inv) {
                q('DELETE FROM invoices WHERE id = ?', [$inv['id']]);
            }
            q('UPDATE todos SET accepted_at = NULL WHERE id = ?', [$todo['id']]);
            redirect($back . '#todo-' . $todo['id']);
        }

        q('UPDATE todos SET accepted_at = NOW() WHERE id = ?', [$todo['id']]);
        $invoiceId = 0;
        if ($isStep) {
            $invoiceId = create_invoice($c, $todo, (float)($todo['agreed_price'] ?? $todo['price']));
        }
        $left = (int)val('SELECT COUNT(*) FROM todos WHERE contract_id = ? AND accepted_at IS NULL', [$c['id']]);
        if ($left === 0) {
            stop_timer((int)$c['id']);
            q("UPDATE contracts SET status = 'fulfilled' WHERE id = ?", [$c['id']]);
            if (!$isStep) {
                create_invoice($c, null, (float)$c['agreed_price']);
                flash('All steps accepted. The contract is fulfilled and an invoice for the whole contract is in Invoices. Please leave a review for the contractor.');
            } else {
                flash('All steps accepted. The contract is fulfilled. Please leave a review for the contractor.');
            }
        } elseif ($isStep) {
            flash('Step accepted. The invoice was added to Invoices (Waiting for payment).');
        }
        if ($invoiceId && ($_POST['pay'] ?? '') === 'now') {
            redirect($back . '?pay=' . $invoiceId);
        }
        redirect($back . '#todo-' . $todo['id']);
}
not_found();
