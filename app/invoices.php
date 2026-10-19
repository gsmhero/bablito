<?php
declare(strict_types=1);

const INVOICE_STATUS = ['waiting' => 'Waiting', 'pending' => 'Pending', 'paid' => 'Paid'];
const PAYMENT_TYPES = ['finish' => 'When Contract Finished', 'step' => 'Step by Step'];

function payment_type_label(?string $t): string
{
    return PAYMENT_TYPES[$t ?? 'finish'] ?? PAYMENT_TYPES['finish'];
}

/**
 * Issues an invoice from the contract author (payer) to the contractor (payee).
 * One invoice per step; a whole-contract invoice has no todo_id. Returns the invoice id.
 */
function create_invoice(array $contract, ?array $todo, float $amount): int
{
    if ($todo) {
        $existing = val('SELECT id FROM invoices WHERE todo_id = ?', [$todo['id']]);
        if ($existing) {
            return (int)$existing;
        }
    } else {
        $existing = val('SELECT id FROM invoices WHERE contract_id = ? AND todo_id IS NULL', [$contract['id']]);
        if ($existing) {
            return (int)$existing;
        }
    }
    q('INSERT INTO invoices (contract_id, todo_id, payer_id, payee_id, amount, currency, status) VALUES (?,?,?,?,?,?,?)',
        [$contract['id'], $todo['id'] ?? null, $contract['user_id'], $contract['contractor_id'], $amount, cur_code($contract), 'waiting']);
    return (int)db()->lastInsertId();
}

function has_unpaid_invoice(int $contractId): bool
{
    return (bool)val("SELECT id FROM invoices WHERE contract_id = ? AND status IN ('waiting','pending') LIMIT 1", [$contractId]);
}

/** Temporary demo payment window (content will be replaced later). Proceed marks the invoice as pending. */
function payment_dialog_html(array $inv, string $domId, bool $open, string $back): string
{
    $sym = CURRENCIES[$inv['currency']][0] ?? '₽';
    ob_start(); ?>
<div class="modal" id="<?= h($domId) ?>" <?= $open ? '' : 'hidden' ?>>
  <div class="modal-card pay-card" role="dialog" aria-modal="true">
    <!-- TEMPORARY DUMMY CONTENT: the payment window will be replaced later -->
    <div class="pay-art">
      <svg viewBox="0 0 320 190" role="img" aria-label="Payment card illustration">
        <rect x="6" y="6" width="308" height="178" rx="16" fill="#12ad00"/>
        <rect x="6" y="46" width="308" height="34" fill="#0b7d00"/>
        <rect x="26" y="108" width="80" height="26" rx="5" fill="#fff" opacity=".9"/>
        <rect x="26" y="148" width="150" height="10" rx="5" fill="#fff" opacity=".7"/>
        <circle cx="262" cy="146" r="18" fill="#fff" opacity=".85"/><circle cx="240" cy="146" r="18" fill="#fff" opacity=".55"/>
      </svg>
    </div>
    <h2>Payment</h2>
    <p class="muted small">Demo payment window. No real money is charged.</p>
    <table class="pay-table">
      <tr><td>Invoice</td><td>#<?= (int)$inv['id'] ?></td></tr>
      <tr><td>Contract</td><td><?= h($inv['contract_title']) ?></td></tr>
      <?php if (!empty($inv['todo_name'])): ?><tr><td>Step</td><td><?= h($inv['todo_name']) ?></td></tr><?php endif; ?>
      <tr><td>Payment method</td><td>Card &bull;&bull;&bull;&bull; 4242</td></tr>
      <tr class="pay-total"><td>Amount</td><td><?= h($sym) ?>&nbsp;<?= money($inv['amount']) ?></td></tr>
    </table>
    <form method="post" action="/invoice/<?= (int)$inv['id'] ?>/pay" class="row between pay-actions">
      <?= csrf_field() ?>
      <input type="hidden" name="back" value="<?= h($back) ?>">
      <button type="submit" class="btn green" data-close="<?= h($domId) ?>">Proceed</button>
      <button type="button" class="btn" data-close="<?= h($domId) ?>">Cancel</button>
    </form>
  </div>
</div>
<?php
    return (string)ob_get_clean();
}
