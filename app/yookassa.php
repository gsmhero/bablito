<?php
declare(strict_types=1);

/*
 * YooKassa (https://yookassa.ru) payments for the service fee that the contract author pays when an offer is
 * accepted. Keys come from the repository secrets YOOKASSA_SHOP_ID / YOOKASSA_SECRET_KEY (written to .env by the deploy).
 *
 * Flow: "Accept Offer" -> payment row (payments) + POST /v3/payments -> redirect to YooKassa -> the author comes back to
 * /payment/return/<id> and YooKassa also calls /yookassa/webhook. Both only ask the API for the real payment status
 * and run payment_finalize(), which is idempotent: the first one that sees "succeeded" accepts the offer.
 * Nothing in a request from outside is trusted; the status always comes from GET /v3/payments/<id>.
 */

const YK_API = 'https://api.yookassa.ru/v3/';

function yk_configured(): bool
{
    return env('YOOKASSA_SHOP_ID') !== '' && env('YOOKASSA_SECRET_KEY') !== '';
}

/** @return array [decoded body or null, error text or null] */
function yk_request(string $method, string $path, ?array $body = null, ?string $idempotence = null): array
{
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($idempotence !== null) {
        $headers[] = 'Idempotence-Key: ' . $idempotence;
    }
    $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $auth = env('YOOKASSA_SHOP_ID') . ':' . env('YOOKASSA_SECRET_KEY');
    if (function_exists('curl_init')) {
        $ch = curl_init(YK_API . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_USERPWD => $auth,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $err = $raw === false ? curl_error($ch) : null;
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $headers[] = 'Authorization: Basic ' . base64_encode($auth);
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $payload ?? '', 'timeout' => 20, 'ignore_errors' => true]]);
        $raw = @file_get_contents(YK_API . $path, false, $ctx);
        $err = $raw === false ? 'cannot reach api.yookassa.ru' : null;
        $code = 0;
        foreach ((array)($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int)$m[1];
            }
        }
    }
    if ($err !== null) {
        mail_log('YooKassa request failed: ' . $err);
        return [null, $err];
    }
    $data = json_decode((string)$raw, true);
    if ($code >= 400 || !is_array($data)) {
        $msg = is_array($data) ? (($data['code'] ?? 'error') . ': ' . ($data['description'] ?? '')) : 'HTTP ' . $code;
        mail_log("YooKassa $method $path -> $code $msg");
        return [null, $msg];
    }
    return [$data, null];
}

function yk_money(float $v): string
{
    return number_format($v, 2, '.', '');
}

/** Receipt (54-FZ) block for the YooKassa API, only when the admin switched receipts on. */
function yk_receipt(array $user, string $description, float $amount, string $currency): ?array
{
    if ((string)(val("SELECT v FROM site_settings WHERE k = 'yk_receipt'") ?? '0') !== '1') {
        return null;
    }
    $r = [
        'customer' => ['email' => (string)$user['email']],
        'items' => [[
            'description' => mb_substr($description, 0, 128),
            'quantity' => '1.00',
            'amount' => ['value' => yk_money($amount), 'currency' => $currency],
            'vat_code' => (int)(val("SELECT v FROM site_settings WHERE k = 'yk_vat_code'") ?: 1),
            'payment_subject' => 'service',
            'payment_mode' => 'full_payment',
        ]],
    ];
    $ts = (int)(val("SELECT v FROM site_settings WHERE k = 'yk_tax_system'") ?: 0);
    if ($ts >= 1 && $ts <= 6) {
        $r['tax_system_code'] = $ts;
    }
    return $r;
}

/** Starts the payment of the service fee for accepting an offer. Returns [confirmation_url, error]. */
function payment_start(array $user, array $contract, array $offer): array
{
    [$amount, $cur] = service_price();
    // a payment that is still open for this offer is reused (double click, back button)
    $open = row("SELECT * FROM payments WHERE offer_id = ? AND user_id = ? AND status = 'pending' AND confirmation_url IS NOT NULL AND created_at > (NOW() - INTERVAL 30 MINUTE) ORDER BY id DESC LIMIT 1", [$offer['id'], $user['id']]);
    if ($open) {
        return [(string)$open['confirmation_url'], null];
    }
    q("INSERT INTO payments (user_id, contract_id, offer_id, amount, currency, status) VALUES (?,?,?,?,?, 'pending')", [$user['id'], $contract['id'], $offer['id'], $amount, $cur]);
    $pid = (int)db()->lastInsertId();
    $desc = mb_substr('Bablito: ' . $contract['title'], 0, 128);
    $body = [
        'amount' => ['value' => yk_money((float)$amount), 'currency' => $cur],
        'capture' => true,
        'confirmation' => ['type' => 'redirect', 'return_url' => rtrim(env('SITE_URL'), '/') . '/payment/return/' . $pid],
        'description' => $desc,
        'metadata' => ['payment_id' => (string)$pid, 'contract_id' => (string)$contract['id'], 'offer_id' => (string)$offer['id']],
    ];
    $rc = yk_receipt($user, 'Bablito: ' . $contract['title'], (float)$amount, $cur);
    if ($rc) {
        $body['receipt'] = $rc;
    }
    [$res, $err] = yk_request('POST', 'payments', $body, 'bbl-' . $pid . '-' . bin2hex(random_bytes(6)));
    if ($err !== null || empty($res['id']) || empty($res['confirmation']['confirmation_url'])) {
        q("UPDATE payments SET status = 'error', error = ? WHERE id = ?", [mb_substr((string)($err ?? 'no confirmation url'), 0, 500), $pid]);
        return [null, $err ?? 'YooKassa did not return a payment page'];
    }
    q('UPDATE payments SET yk_id = ?, confirmation_url = ? WHERE id = ?', [$res['id'], $res['confirmation']['confirmation_url'], $pid]);
    return [(string)$res['confirmation']['confirmation_url'], null];
}

/** The acceptance itself (shared by the free flow and the paid one). */
function accept_offer_and_notify(array $c, array $offer, array $actor): void
{
    require_once ROOT . '/app/offers.php';
    $others = array_column(rows("SELECT user_id FROM offers WHERE contract_id = ? AND id <> ? AND status = 'pending'", [$c['id'], $offer['id']]), 'user_id');
    $pdo = db();
    $pdo->beginTransaction();
    accept_offer_terms($c, $offer);
    $pdo->commit();
    notify('offer_accepted', $c, ['actor' => $actor, 'to' => [(int)$offer['user_id']], 'url' => '/contract/' . (int)$c['id']]);
    notify('offer_lost', $c, ['actor' => $actor, 'to' => $others, 'url' => '/contracts']);
}

/**
 * Looks at the real status in YooKassa and finishes the payment. Safe to call many times and from two places at once:
 * only the call that flips the row to "paid" accepts the offer.
 * @return string paid | pending | canceled | error | orphan (paid, but the offer is no longer available)
 */
function payment_finalize(int $payId): string
{
    $p = row('SELECT * FROM payments WHERE id = ?', [$payId]);
    if (!$p || empty($p['yk_id'])) {
        return 'error';
    }
    if ($p['status'] === 'paid' || $p['status'] === 'orphan') {
        return (string)$p['status'];
    }
    [$res, $err] = yk_request('GET', 'payments/' . rawurlencode((string)$p['yk_id']));
    if ($err !== null) {
        return 'error';
    }
    if (($res['status'] ?? '') === 'canceled') {
        q("UPDATE payments SET status = 'canceled', error = ? WHERE id = ? AND status = 'pending'", [mb_substr((string)($res['cancellation_details']['reason'] ?? ''), 0, 200), $payId]);
        return 'canceled';
    }
    if (($res['status'] ?? '') !== 'succeeded' || empty($res['paid'])) {
        return 'pending';
    }
    // the amount must be what we asked for
    if (abs((float)($res['amount']['value'] ?? 0) - (float)$p['amount']) > 0.001) {
        q("UPDATE payments SET status = 'error', error = 'amount mismatch' WHERE id = ?", [$payId]);
        return 'error';
    }
    $own = q("UPDATE payments SET status = 'paid', paid_at = NOW() WHERE id = ? AND status = 'pending'", [$payId])->rowCount() === 1;
    if (!$own) {
        return 'paid';
    }
    $offer = row('SELECT * FROM offers WHERE id = ?', [$p['offer_id']]);
    $c = $offer ? load_contract((int)$offer['contract_id']) : null;
    $actor = row('SELECT * FROM users WHERE id = ?', [$p['user_id']]);
    if (!$offer || !$c || !$actor || $offer['status'] !== 'pending' || $c['status'] !== 'published') {
        q("UPDATE payments SET status = 'orphan', error = 'paid, but the offer was not available' WHERE id = ?", [$payId]);
        return 'orphan';
    }
    accept_offer_and_notify($c, $offer, $actor);
    return 'paid';
}

/** IPv4 / IPv6 address in a CIDR range. */
function ip_in_cidr(string $ip, string $cidr): bool
{
    [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
    $a = @inet_pton($ip);
    $b = @inet_pton((string)$net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) {
        return false;
    }
    $bits = $bits === null ? strlen($a) * 8 : (int)$bits;
    $full = intdiv($bits, 8);
    if ($full && substr($a, 0, $full) !== substr($b, 0, $full)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return (ord($a[$full]) & $mask) === (ord($b[$full]) & $mask);
}

const YK_WEBHOOK_IPS = ['185.71.76.0/27', '185.71.77.0/27', '77.75.153.0/25', '77.75.156.11', '77.75.156.35', '77.75.154.128/25', '2a02:5180::/32'];

function yk_ip_allowed(string $ip): bool
{
    foreach (YK_WEBHOOK_IPS as $c) {
        if (ip_in_cidr($ip, $c)) {
            return true;
        }
    }
    return false;
}
