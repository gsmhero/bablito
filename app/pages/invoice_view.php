<?php
declare(strict_types=1);

$u = require_login();
$inv = row("SELECT i.*, c.title AS contract_title, c.author_type, c.accepted_offer_id, t.name AS todo_name, t.position AS todo_pos
    FROM invoices i JOIN contracts c ON c.id = i.contract_id LEFT JOIN todos t ON t.id = i.todo_id WHERE i.id = ?", [(int)$args[0]]);
if (!$inv) {
    not_found();
}
if ((int)$inv['payer_id'] !== (int)$u['id'] && (int)$inv['payee_id'] !== (int)$u['id'] && !is_admin()) {
    forbidden();
}

// From = contractor (issues the invoice), To = contract author (pays).
$from = row('SELECT * FROM users WHERE id = ?', [$inv['payee_id']]);
$to = row('SELECT * FROM users WHERE id = ?', [$inv['payer_id']]);
$fromType = (string)(val('SELECT offerer_type FROM offers WHERE id = ?', [$inv['accepted_offer_id']]) ?: 'personal');
$toType = (string)($inv['author_type'] ?: 'personal');
$tpl = pick_invoice_template($fromType, $toType);

$block = function (string $title, ?string $json, array $user) use ($inv): void {
    echo '<div class="inv-block"><h3>' . h($title) . '</h3>';
    foreach (invoice_tokens($json) as $tok) {
        $fv = invoice_field_value($tok, $inv, $user);
        if ($fv) {
            echo '<div class="inv-line"><span class="inv-label">' . h($fv[0]) . '</span> ' . nl2br(h($fv[1])) . '</div>';
        }
    }
    echo '</div>';
};

render('Invoice #' . $inv['id'], function () use ($inv, $tpl, $from, $to, $fromType, $toType, $block) { ?>
<div class="wrap">
  <div class="invoice-doc">
    <?php if ($tpl): ?>
      <div class="inv-head"><?= $tpl['header_html'] /* sanitized when the template is saved */ ?></div>
      <div class="inv-two">
        <?php $block('From', json_encode(invoice_block_tokens($tpl, 'from')), $from); $block('To', json_encode(invoice_block_tokens($tpl, 'to')), $to); ?>
      </div>
      <div class="inv-content"><?php $block('Details', $tpl['content_fields'], $from); ?></div>
      <div class="inv-foot"><?= $tpl['footer_html'] ?></div>
    <?php else: ?>
      <h1>Invoice #<?= (int)$inv['id'] ?></h1>
      <p class="muted small">No template is set up for <?= h(invoice_pair_label($fromType, $toType)) ?>, so the default layout is shown.</p>
      <div class="inv-two">
        <div class="inv-block"><h3>From</h3><div><?= h(display_name($from)) ?></div></div>
        <div class="inv-block"><h3>To</h3><div><?= h(display_name($to)) ?></div></div>
      </div>
      <div class="inv-content">
        <div class="inv-line"><span class="inv-label">Contract</span> <?= h($inv['contract_title']) ?></div>
        <div class="inv-line"><span class="inv-label">Step</span> <?= h($inv['todo_name'] ?? 'Whole contract') ?></div>
        <div class="inv-line"><span class="inv-label">Price</span> <?= h(CURRENCIES[$inv['currency']][0] ?? '') ?> <?= money($inv['amount']) ?></div>
        <div class="inv-line"><span class="inv-label">Status</span> <?= h(INVOICE_STATUS[$inv['status']] ?? $inv['status']) ?></div>
      </div>
    <?php endif; ?>
  </div>
  <p class="right"><button class="btn" type="button" onclick="window.print()">Print</button></p>
</div>
<?php }, ['back' => '/invoices']);
