<?php
declare(strict_types=1);

// The author comes back from the YooKassa page. The status is asked from YooKassa, not taken from the URL.
$u = require_login();
$p = row('SELECT * FROM payments WHERE id = ?', [(int)$args[0]]);
if (!$p || (int)$p['user_id'] !== (int)$u['id']) {
    not_found();
}
$state = payment_finalize((int)$p['id']);
$offerBack = '/offer/' . (int)$p['offer_id'];
$contractBack = '/contract/' . (int)$p['contract_id'];

switch ($state) {
    case 'paid':
        flash('Payment received. The offer is accepted and the contract is now active.');
        redirect($contractBack);
    case 'canceled':
        flash('The payment was not completed. The offer is not accepted yet.', 'err');
        redirect($offerBack);
    case 'orphan':
        flash('The payment was received, but the offer is no longer available. Please write to ' . 'admin@bablito.ru' . ' and we will return the money.', 'err');
        redirect($contractBack);
    case 'pending':
        render('Payment', function () use ($p, $offerBack) { ?>
<div class="wrap narrow"><h1>Payment is being processed</h1>
  <p>YooKassa has not confirmed the payment yet. This usually takes a few seconds.</p>
  <p><a class="btn green" href="/payment/return/<?= (int)$p['id'] ?>">Check again</a> <a class="btn" href="<?= h($offerBack) ?>">Back to the offer</a></p>
</div>
<?php });
        exit;
    default:
        flash('We could not check the payment status. If money was taken, the offer will be accepted automatically in a few minutes.', 'err');
        redirect($offerBack);
}
