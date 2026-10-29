<?php
declare(strict_types=1);

// YooKassa notification (payment.succeeded / payment.canceled). The body is never trusted: only the payment id is
// taken from it and the real status is read from the API. The answer is always HTTP 200, as YooKassa requires.
header('Content-Type: text/plain; charset=utf-8');
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!yk_ip_allowed($ip)) {
    mail_log('YooKassa webhook from an address outside the YooKassa list: ' . $ip);
}
$data = json_decode((string)file_get_contents('php://input'), true);
$ykId = is_array($data) ? (string)($data['object']['id'] ?? '') : '';
if ($ykId !== '' && yk_configured()) {
    $pid = (int)val('SELECT id FROM payments WHERE yk_id = ?', [$ykId]);
    if ($pid) {
        try {
            payment_finalize($pid);
        } catch (Throwable $e) {
            error_log('yookassa webhook: ' . $e);
        }
    }
}
http_response_code(200);
echo 'ok';
exit;
