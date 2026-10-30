<?php
declare(strict_types=1);

/*
 * VK Mini Apps and OK.ru apps. Both open this same site in a frame. The platform adds signed launch parameters to the
 * address (/vk or /ok); we verify the signature with the app secret, find or create the user and log them in.
 * Nothing in the launch address is trusted without the signature check.
 *
 * Third-party cookies can be blocked inside a frame (Safari), so in embed mode the session id is also carried in
 * "_s" on local links when the browser did not send the cookie (see embed_sid_filter / embed_url).
 */

function embed_platform(): string
{
    $p = (string)($_SESSION['embed'] ?? '');
    return $p === 'vk' || $p === 'ok' ? $p : '';
}

/** The session id travels in the address only when the cookie does not come back. */
function embed_needs_sid(): bool
{
    return embed_platform() !== '' && empty($_COOKIE[session_name()]);
}

function embed_url(string $url): string
{
    if (!embed_needs_sid() || $url === '' || $url[0] !== '/' || strncmp($url, '//', 2) === 0) {
        return $url;
    }
    $frag = '';
    if (($i = strpos($url, '#')) !== false) {
        $frag = substr($url, $i);
        $url = substr($url, 0, $i);
    }
    return $url . (strpos($url, '?') === false ? '?' : '&') . '_s=' . rawurlencode(session_id()) . $frag;
}

/** Output filter: adds the session id to local links and forms. */
function embed_sid_filter(string $html): string
{
    $html = preg_replace_callback('#\b(href|action)="(/(?!/)[^"]*)"#', function ($m) {
        $u = html_entity_decode($m[2], ENT_QUOTES);
        if (preg_match('#^/(assets|uploads)/#', $u)) {
            return $m[0];
        }
        return $m[1] . '="' . htmlspecialchars(embed_url($u), ENT_QUOTES) . '"';
    }, $html) ?? $html;
    // a GET form drops the query of its action, so the id goes in as a hidden field
    return preg_replace('#(<form\b[^>]*\bmethod="get"[^>]*>)#i', '$1<input type="hidden" name="_s" value="' . htmlspecialchars(session_id(), ENT_QUOTES) . '">', $html) ?? $html;
}

/* ---------- signature checks ---------- */

function vk_verify(array $q): ?string
{
    $secret = env('VK_SECRET_KEY');
    if ($secret === '' || empty($q['sign']) || !is_string($q['sign'])) {
        return null;
    }
    $p = [];
    foreach ($q as $k => $v) {
        if (is_string($k) && strncmp($k, 'vk_', 3) === 0 && is_string($v)) {
            $p[$k] = $v;
        }
    }
    if (!isset($p['vk_user_id']) || !preg_match('/^\d{1,20}$/', $p['vk_user_id']) || $p['vk_user_id'] === '0') {
        return null;
    }
    if (env('VK_APP_ID') !== '' && ($p['vk_app_id'] ?? '') !== env('VK_APP_ID')) {
        return null;
    }
    ksort($p);
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', http_build_query($p, '', '&', PHP_QUERY_RFC3986), $secret, true)), '+/', '-_'), '=');
    return hash_equals($sig, $q['sign']) ? $p['vk_user_id'] : null;
}

function ok_verify(array $q): ?string
{
    $secret = env('OK_SECRET_KEY');
    $id = (string)($q['logged_user_id'] ?? '');
    $key = (string)($q['session_key'] ?? '');
    $sig = strtolower((string)($q['auth_sig'] ?? ''));
    if ($secret === '' || !preg_match('/^\d{1,20}$/', $id) || $key === '' || $sig === '') {
        return null;
    }
    return hash_equals(md5($id . $key . $secret), $sig) ? $id : null;
}

/* ---------- users ---------- */

function social_user(string $platform, string $extId): array
{
    $col = $platform === 'vk' ? 'vk_id' : 'ok_id';
    $u = row("SELECT * FROM users WHERE $col = ?", [$extId]);
    if ($u) {
        return $u;
    }
    // no e-mail of the person is known: a technical address that is never mailed (smtp_send skips ".invalid")
    $email = $platform . $extId . '@' . $platform . '.bablito.invalid';
    q("INSERT INTO users (email, pass_hash, name, contact_email, timezone, user_types, lang, notify_email, $col) VALUES (?,?,?,?,?,?,?,0,?)",
        [$email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), '', '', 'Europe/Moscow', implode(',', types_from_post([])), 'ru', $extId]);
    return row('SELECT * FROM users WHERE id = ?', [(int)db()->lastInsertId()]);
}

/* ---------- page parts ---------- */

/** Scripts the platform needs in the page head; the VK / OK start-up calls and the one-time name fetch. */
function embed_head_html(): string
{
    $p = embed_platform();
    if ($p === '') {
        return '';
    }
    $u = user();
    $needName = $u && trim((string)$u['name']) === '';
    $post = json_encode(embed_url('/social/name'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    $csrf = json_encode(csrf_token());
    $send = "function bblName(n){if(!n){return;}var f=new FormData();f.append('_csrf',$csrf);f.append('name',n);fetch($post,{method:'POST',body:f,credentials:'include'}).then(function(r){if(r.ok){location.reload();}});}";
    if ($p === 'vk') {
        return '<script src="https://unpkg.com/@vkontakte/vk-bridge/dist/browser.min.js"></script>'
            . '<script>' . $send . 'if(window.vkBridge){vkBridge.send("VKWebAppInit");'
            . ($needName ? 'vkBridge.send("VKWebAppGetUserInfo").then(function(d){bblName(((d.first_name||"")+" "+(d.last_name||"")).trim());}).catch(function(){});' : '')
            . '}</script>';
    }
    $api = (array)($_SESSION['ok_api'] ?? []);
    $srv = json_encode((string)($api['server'] ?? ''), JSON_HEX_TAG);
    $con = json_encode((string)($api['conn'] ?? ''), JSON_HEX_TAG);
    return '<script src="https://api.ok.ru/js/fapi5.js"></script>'
        . '<script>' . $send . 'if(window.FAPI&&' . $srv . '){FAPI.init(' . $srv . ',' . $con . ',function(){'
        . ($needName ? 'FAPI.Client.call({method:"users.getCurrentUser",fields:"name"},function(s,r){if(s==="ok"&&r&&r.name){bblName(r.name);}});' : '')
        . '},function(){});}</script>';
}

/** "Continue to payment": YooKassa cannot be shown inside the frame, so it opens in its own window. */
function embed_pay_page(string $url, string $back): void
{
    $plat = embed_platform();
    render('Payment', function () use ($url, $back, $plat) { ?>
<div class="narrow card-form" style="text-align:center">
  <h1>Payment</h1>
  <p>The payment page opens in a new window. When you have paid, come back here and press the button below.</p>
  <p><a class="btn green" id="pay-open" href="<?= h($url) ?>" target="_blank" rel="noopener">Go to payment</a></p>
  <p><a class="btn" href="<?= h(embed_url($back)) ?>">I have paid. Back to the offer</a></p>
</div>
<?php if ($plat === 'vk'): ?><script>document.getElementById('pay-open').addEventListener('click',function(e){if(window.vkBridge){e.preventDefault();vkBridge.send('VKWebAppOpenLink',{url:this.href});}});</script><?php endif; ?>
<?php });
    exit;
}
