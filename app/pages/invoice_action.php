<?php
declare(strict_types=1);

$u = require_login();
$inv = row('SELECT * FROM invoices WHERE id = ?', [(int)$args[0]]);
if (!$inv) {
    not_found();
}
$back = safe_local_path((string)($_POST['back'] ?? '/invoices'));

if ($args[1] === 'pay') {
    // The payer starts the payment (demo): the invoice waits for the contractor to confirm receipt.
    if ((int)$inv['payer_id'] !== (int)$u['id'] || $inv['status'] !== 'waiting') {
        forbidden();
    }
    q("UPDATE invoices SET status = 'pending' WHERE id = ?", [$inv['id']]);
    $ic = load_contract((int)$inv['contract_id']);
    $tot = tax_for_invoice($inv)['total'];
    notify('invoice_paid', $ic, ['to' => [(int)$inv['payee_id']], 'url' => '/invoices?tab=receive', 'extra' => ['en' => 'Amount: ' . money($tot) . ' ' . $inv['currency'], 'ru' => 'Сумма: ' . money($tot) . ' ' . $inv['currency']]]);
    flash('Payment sent. The invoice is pending until the contractor confirms it.');
    redirect($back);
}

if ($args[1] === 'confirm') {
    if ((int)$inv['payee_id'] !== (int)$u['id'] || $inv['status'] !== 'pending') {
        forbidden();
    }
    $taxNow = tax_for_invoice(invoice_full((int)$inv['id']) ?: $inv);
    q("UPDATE invoices SET status = 'paid', paid_at = NOW(), tax_json = ? WHERE id = ?", [json_encode($taxNow, JSON_UNESCAPED_UNICODE), $inv['id']]);
    $ic = load_contract((int)$inv['contract_id']);
    notify('invoice_confirmed', $ic, ['to' => [(int)$inv['payer_id']], 'url' => '/invoices?tab=pay', 'extra' => ['en' => 'Amount: ' . money($taxNow['total']) . ' ' . $inv['currency'], 'ru' => 'Сумма: ' . money($taxNow['total']) . ' ' . $inv['currency']]]);
    flash('Payment confirmed.');
    redirect('/invoices?tab=receive');
}
not_found();
