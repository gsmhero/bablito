<?php
declare(strict_types=1);

// The author comes back from the YooKassa page. The status is asked from YooKassa, not taken from the URL.
$u = user();
$p = row('SELECT * FROM payments WHERE id = ?', [(int)$args[0]]);
if (!$p || ($u && (int)$p['user_id'] !== (int)$u['id'])) {
    not_found();
}
if (!$u) {
    // YooKassa opened in its own window (VK / OK app): there is no session here, so only the status is shown
    $state = payment_finalize((int)$p['id']);
    render('Payment', function () use ($state, $p) { ?>
<div class="narrow card-form" style="text-align:center">
  <h1>Payment</h1>
  <?php if ($state === 'paid'): ?><p>Payment received. You can close this window and return to the application.</p>
  <?php elseif ($state === 'canceled'): ?><p>The payment was not completed. You can close this window and try again in the application.</p>
  <?php elseif ($state === 'orphan'): ?><p>The payment was received, but the offer is no longer available. Please write to admin@bablito.ru and we will return the money.</p>
  <?php else: ?><p>YooKassa has not confirmed the payment yet.</p><p><a class="btn green" href="/payment/return/<?= (int)$p['id'] ?>">Check again</a></p><?php endif; ?>
</div>
<?php });
    exit;
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
