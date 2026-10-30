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
redirect('/contracts');
