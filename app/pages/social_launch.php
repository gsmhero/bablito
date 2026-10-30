<?php
declare(strict_types=1);

// Start page of the VK Mini App (/vk) and of the OK.ru app (/ok): signed launch parameters in, logged-in user out.
$platform = (string)$args[0];
$ext = $platform === 'vk' ? vk_verify($_GET) : ok_verify($_GET);
if ($ext === null) {
    http_response_code(403);
    render('bablito', function () use ($platform) { ?>
<div class="narrow card-form" style="text-align:center">
  <h1>bablito</h1>
  <p>Open this application from <?= $platform === 'vk' ? 'VK' : 'OK.ru' ?>.</p>
  <p><a class="btn green" href="https://bablito.ru/">bablito.ru</a></p>
</div>
<?php });
    exit;
}
$u = social_user($platform, $ext);
if (!empty($u['blocked'])) {
    forbidden();
}
login_user((int)$u['id']);
$_SESSION['embed'] = $platform;
if ($platform === 'ok') {
    $srv = (string)($_GET['api_server'] ?? '');
    $_SESSION['ok_api'] = ['server' => preg_match('#^https://[a-z0-9.-]+\.ok\.ru/?$#i', $srv) ? $srv : '', 'conn' => (string)($_GET['apiconnection'] ?? '')];
}
set_lang_cookie(in_array($u['lang'] ?? '', SUPPORTED_LANGS, true) ? (string)$u['lang'] : 'ru');
// A post on a wall links to #/contract/N: the hash never reaches the server, so a tiny page forwards it.
$sid = json_encode(embed_needs_sid() ? session_id() : '');
echo '<!doctype html><meta charset="utf-8"><title>bablito</title><script>var m=location.hash.match(/^#\/contract\/(\d+)$/),s=' . $sid . ';'
    . 'location.replace(m?"/contract/"+m[1]+(s?"?_s="+s:""):' . json_encode(embed_url('/contracts'), JSON_UNESCAPED_SLASHES) . ');</script>';
