<?php
declare(strict_types=1);

$u = require_login();
$inv = invoice_full((int)$args[0]);
if (!$inv) {
    not_found();
}
if ((int)$inv['payer_id'] !== (int)$u['id'] && (int)$inv['payee_id'] !== (int)$u['id'] && !is_admin()) {
    forbidden();
}
$pdf = invoice_pdf($inv, user_lang($u));
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="invoice-' . (int)$inv['id'] . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit;
