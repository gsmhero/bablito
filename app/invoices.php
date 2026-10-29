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
    $id = (int)db()->lastInsertId();
    mail_invoice_copy($id);
    return $id;
}

function has_unpaid_invoice(int $contractId): bool
{
    return (bool)val("SELECT id FROM invoices WHERE contract_id = ? AND status IN ('waiting','pending') LIMIT 1", [$contractId]);
}

/**
 * Profile fields that hold the payee's "transfer to phone number" details, by the payee's status on the contract:
 * [phone field id, preferred banks field id]. A Company is paid by invoice only.
 */
const PAY_PHONE_FIELDS = ['personal' => [43, 47], 'self' => [48, 49]];

/** Payee status on the contract (personal | self | company) and, for personal / self-employed, the phone and banks. */
function invoice_payee_info(array $inv): array
{
    $type = (string)(val('SELECT o.offerer_type FROM contracts c JOIN offers o ON o.id = c.accepted_offer_id WHERE c.id = ?', [$inv['contract_id']]) ?: 'personal');
    $phone = $banks = '';
    if (isset(PAY_PHONE_FIELDS[$type])) {
        [$fp, $fb] = PAY_PHONE_FIELDS[$type];
        $get = fn(int $fid): string => trim((string)(val('SELECT value FROM profile_values WHERE user_id = ? AND field_id = ?', [$inv['payee_id'], $fid]) ?? ''));
        $phone = $get($fp);
        $banks = $get($fb);
    }
    return [$type, $phone, $banks];
}

/**
 * Payment window of an invoice. The contractor is paid directly by the author (the site only takes the service fee):
 * Personal / Self-Employed: phone transfer details, "Download Invoice" and "Proceed" that shows what the contractor accepts;
 * Company: by invoice, "Download Invoice". "I have paid" (POST /invoice/N/pay) marks the invoice as pending.
 */
function payment_dialog_html(array $inv, string $domId, bool $open, string $back): string
{
    [$type, $phone, $banks] = invoice_payee_info($inv);
    $byPhone = isset(PAY_PHONE_FIELDS[$type]);
    $infoId = $domId . '-info';
    $pdf = '/invoice/' . (int)$inv['id'] . '.pdf';
    ob_start(); ?>
<div class="modal" id="<?= h($domId) ?>" <?= $open ? '' : 'hidden' ?>>
  <div class="modal-card pay-card" role="dialog" aria-modal="true">
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
    <table class="pay-table">
      <tr><td>Invoice</td><td>#<?= (int)$inv['id'] ?></td></tr>
      <tr><td>Contract</td><td><?= h($inv['contract_title']) ?></td></tr>
      <?php if (!empty($inv['todo_name'])): ?><tr><td>Step</td><td><?= h($inv['todo_name']) ?></td></tr><?php endif; ?>
      <?php if ($byPhone): ?>
      <tr><td>Payment method</td><td>Transfer to phone number</td></tr>
      <tr><td>Phone number</td><td><?= $phone !== '' ? h($phone) : '&mdash;' ?></td></tr>
      <?php if ($banks !== ''): ?><tr><td>Preferred banks</td><td><?= nl2br(h($banks)) ?></td></tr><?php endif; ?>
      <?php endif; ?>
      <?php if ($type === 'self'): ?><tr><td>Payment method</td><td>By invoice</td></tr>
      <?php elseif ($type === 'company'): ?><tr><td>Payment method</td><td>By invoice</td></tr><?php endif; ?>
      <tr class="pay-total"><td>Amount</td><td><?= h(tax_money((float)tax_for_invoice($inv)['total'], $inv['currency'])) ?></td></tr>
    </table>
    <div class="row between pay-actions">
      <a class="btn" href="<?= h($pdf) ?>" target="_blank" rel="noopener">Download Invoice</a>
      <?php if ($byPhone): ?>
        <button type="button" class="btn green" data-open="<?= h($infoId) ?>" data-hide="<?= h($domId) ?>"><?= $type === 'self' ? 'Proceed on Phone' : 'Proceed' ?></button>
      <?php else: ?>
        <?= payment_paid_form($inv, $back, $domId, 'I have paid') ?>
      <?php endif; ?>
    </div>
    <p class="row pay-actions" style="margin-top:10px"><button type="button" class="btn" data-close="<?= h($domId) ?>">Cancel</button></p>
  </div>
</div>
<?php if ($byPhone): ?>
<div class="modal" id="<?= h($infoId) ?>" hidden>
  <div class="modal-card pay-card" role="dialog" aria-modal="true">
    <h2>Payment</h2>
    <p>I accept payments via phone number</p>
    <p><b><?= $phone !== '' ? h($phone) : '&mdash;' ?></b></p>
    <?php if ($banks !== ''): ?><p>preferred banks:</p><p><b><?= nl2br(h($banks)) ?></b></p><?php endif; ?>
    <div class="row between pay-actions">
      <?= payment_paid_form($inv, $back, $infoId, 'I have paid') ?>
      <button type="button" class="btn" data-close="<?= h($infoId) ?>">Cancel</button>
    </div>
  </div>
</div>
<?php endif; ?>
<?php
    return (string)ob_get_clean();
}

/** The "I have paid" form: the invoice becomes pending until the contractor confirms the money. */
function payment_paid_form(array $inv, string $back, string $domId, string $label): string
{
    return '<form method="post" action="/invoice/' . (int)$inv['id'] . '/pay" class="inline">' . csrf_field()
        . '<input type="hidden" name="back" value="' . h($back) . '">'
        . '<button type="submit" class="btn green" data-close="' . h($domId) . '">' . h($label) . '</button></form>';
}
