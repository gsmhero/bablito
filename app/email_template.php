<?php
declare(strict_types=1);

// HTML e-mail template for notifications: table layout with inline styles (works in Gmail, Apple Mail,
// Outlook and the mobile clients), 600 px card that becomes full width on phones, dark mode support.

function eh($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * $d: lang, title, preheader, tag, who, headline, contract, rows [[label, value], ...],
 *     comment [body, files, label, files_label], button, url, footer, settings_label, settings_url, site_url
 */
function email_html(array $d): string
{
    $lang = $d['lang'] ?? 'en';
    $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";
    $who = (string)($d['who'] ?? '');
    $initial = $who !== '' ? mb_strtoupper(mb_substr($who, 0, 1)) : 'B';
    $url = (string)($d['url'] ?? '');
    $site = (string)($d['site_url'] ?? '');

    $rows = '';
    foreach (($d['rows'] ?? []) as [$label, $value]) {
        $rows .= '<tr><td class="muted" style="padding:6px 16px 6px 0;font:14px/20px ' . $font . ';color:#6b7280;white-space:nowrap;vertical-align:top">' . eh($label) . '</td>'
            . '<td class="text" style="padding:6px 0;font:600 14px/20px ' . $font . ';color:#1f2937;vertical-align:top">' . nl2br(eh($value)) . '</td></tr>';
    }

    $comment = '';
    $c = $d['comment'] ?? null;
    if ($c && ((string)($c['body'] ?? '') !== '' || !empty($c['files']))) {
        $inner = '';
        if ((string)($c['body'] ?? '') !== '') {
            $inner .= '<div class="text" style="font:16px/24px ' . $font . ';color:#1f2937">' . nl2br(eh($c['body'])) . '</div>';
        }
        if (!empty($c['files'])) {
            $inner .= '<div style="margin-top:' . ((string)($c['body'] ?? '') !== '' ? '12' : '0') . 'px"><span class="chip" style="display:inline-block;padding:5px 12px;border-radius:14px;background:#e6f4e0;color:#0a6b1b;font:600 13px/18px ' . $font . '">&#128206; ' . eh($c['files_label'] ?? 'Comment has attachments') . '</span></div>';
        }
        $comment = '<tr><td style="padding:8px 0 4px"><div class="muted" style="font:600 12px/16px ' . $font . ';letter-spacing:.6px;text-transform:uppercase;color:#6b7280;padding-bottom:8px">' . eh($c['label'] ?? 'Comment') . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="quote" style="padding:16px 18px;background:#f3f9ee;border-left:4px solid #00bc1f;border-radius:0 12px 12px 0">' . $inner . '</td></tr></table></td></tr>';
    }

    $button = '';
    if ($url !== '') {
        $button = '<tr><td style="padding:24px 0 8px">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="btn-wrap"><tr>'
            . '<td align="center" bgcolor="#00bc1f" style="border-radius:12px;background:#00bc1f">'
            . '<a href="' . eh($url) . '" class="btn" style="display:inline-block;padding:15px 32px;font:700 16px/20px ' . $font . ';color:#ffffff;text-decoration:none;border-radius:12px">' . eh($d['button'] ?? 'Open') . ' &rarr;</a>'
            . '</td></tr></table>'
            . '<div class="muted" style="margin-top:14px;font:12px/18px ' . $font . ';color:#8a919b;word-break:break-all">' . eh($d['link_hint'] ?? '') . ' <a href="' . eh($url) . '" style="color:#0a8a1f;text-decoration:underline">' . eh($url) . '</a></div>'
            . '</td></tr>';
    }

    $tag = (string)($d['tag'] ?? '');
    $tagHtml = $tag !== '' ? '<span style="display:inline-block;padding:5px 12px;border-radius:14px;background:rgba(255,255,255,.22);color:#ffffff;font:600 12px/16px ' . $font . ';letter-spacing:.4px">' . eh($tag) . '</span>' : '';

    return '<!doctype html>
<html lang="' . eh($lang) . '" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>' . eh($d['title'] ?? 'bablito') . '</title>
<style>
  body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
  table,td{mso-table-lspace:0;mso-table-rspace:0}
  img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic}
  a{color:#0a8a1f}
  @media only screen and (max-width:620px){
    .outer{padding:0!important}
    .container{width:100%!important;max-width:100%!important}
    .card{border-radius:0!important}
    .px{padding-left:20px!important;padding-right:20px!important}
    .headline{font-size:22px!important;line-height:30px!important}
    .btn-wrap{width:100%!important}
    .btn{display:block!important;padding:16px 20px!important}
    .stack{display:block!important;width:100%!important}
  }
  @media (prefers-color-scheme:dark){
    body,.bg{background:#0f1215!important}
    .card{background:#1a1f24!important}
    .text{color:#eceff3!important}
    .muted{color:#9aa3ad!important}
    .quote{background:#1f2c20!important;border-left-color:#2fd04a!important}
    .chip{background:#24402a!important;color:#9ff0ae!important}
    .rule{border-color:#2a3138!important}
    .foot a{color:#7ee08f!important}
  }
</style>
</head>
<body class="bg" style="margin:0;padding:0;background:#f2f4f6;font-family:' . $font . '">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all">' . eh($d['preheader'] ?? '') . str_repeat('&nbsp;&zwnj;', 40) . '</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg" style="background:#f2f4f6">
<tr><td align="center" class="outer" style="padding:28px 12px">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" class="container" style="width:600px;max-width:600px">
    <tr><td class="px" style="padding:0 4px 14px">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
        <td style="font:800 28px/32px ' . $font . ';letter-spacing:-.5px;color:#00bc1f"><a href="' . eh($site) . '" style="color:#00bc1f;text-decoration:none">bablito</a></td>
        <td align="right">' . ($tag !== '' ? '<span class="chip" style="display:inline-block;padding:5px 12px;border-radius:14px;background:#e6f4e0;color:#0a6b1b;font:600 12px/16px ' . $font . ';letter-spacing:.4px">' . eh($tag) . '</span>' : '') . '</td>
      </tr></table>
    </td></tr>
    <tr><td class="card" style="background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 2px 14px rgba(15,23,42,.08)">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr><td style="height:6px;line-height:6px;font-size:0;background:#00bc1f;background-image:linear-gradient(90deg,#00bc1f,#2fd04a)">&nbsp;</td></tr>
        <tr><td class="px" style="padding:28px 32px 8px">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
            <td width="48" height="48" align="center" valign="middle" bgcolor="#00bc1f" style="width:48px;height:48px;border-radius:24px;background:#00bc1f;color:#ffffff;font:700 22px/48px ' . $font . '">' . eh($initial) . '</td>
            <td style="padding-left:14px;font:600 15px/20px ' . $font . ';color:#1f2937" class="text">' . eh($who) . '<div class="muted" style="font:400 13px/18px ' . $font . ';color:#6b7280">' . eh($d['when'] ?? '') . '</div></td>
          </tr></table>
        </td></tr>
        <tr><td class="px" style="padding:12px 32px 4px">
          <h1 class="headline text" style="margin:0;font:700 26px/34px ' . $font . ';color:#111827;letter-spacing:-.3px">' . eh($d['headline'] ?? '') . '</h1>
        </td></tr>
        <tr><td class="px" style="padding:12px 32px 28px">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr><td style="padding:0 0 12px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="rule" style="border-top:1px solid #eceff3;font-size:0;line-height:0">&nbsp;</td></tr></table></td></tr>
            <tr><td><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">' . $rows . '</table></td></tr>
            ' . $comment . '
            ' . $button . '
          </table>
        </td></tr>
      </table>
    </td></tr>
    <tr><td class="px foot" style="padding:20px 20px 4px;text-align:center;font:12px/19px ' . $font . ';color:#8a919b">
      <div class="muted" style="color:#8a919b">' . eh($d['footer'] ?? '') . '</div>
      <div style="padding-top:8px"><a href="' . eh($d['settings_url'] ?? $site) . '" style="color:#0a8a1f;text-decoration:underline">' . eh($d['settings_label'] ?? '') . '</a></div>
      <div class="muted" style="padding-top:12px;color:#a3aab3">bablito &middot; ' . eh(preg_replace('#^https?://#', '', $site)) . '</div>
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>';
}

/** Example notification used by the admin preview and the test e-mail. */
function email_sample_html(string $lang): string
{
    $ru = $lang === 'ru';
    $site = site_base_url();
    return email_html([
        'lang' => $lang,
        'title' => $ru ? 'Тестовое письмо bablito' : 'bablito test e-mail',
        'preheader' => $ru ? 'Так выглядят уведомления bablito' : 'This is how bablito notifications look',
        'tag' => $ru ? 'Комментарий' : 'Comment',
        'who' => 'Dmitry',
        'when' => date('d.m.Y H:i'),
        'headline' => $ru ? 'Dmitry оставил комментарий' : 'Dmitry posted a comment',
        'rows' => [[$ru ? 'Контракт' : 'Contract', $ru ? 'Ремонт крыши' : 'Fix the roof'], [$ru ? 'Шаг' : 'Step', $ru ? 'Черепица' : 'Tiles']],
        'comment' => ['body' => $ru ? 'Фотографии крыши во вложении, посмотрите, пожалуйста.' : 'Photos of the roof are attached, please have a look.', 'files' => true, 'label' => $ru ? 'Комментарий' : 'Comment', 'files_label' => $ru ? 'К комментарию приложены файлы' : 'Comment has attachments'],
        'button' => $ru ? 'Открыть в bablito' : 'Open in bablito',
        'url' => $site . '/notifications',
        'link_hint' => $ru ? 'Если кнопка не работает, откройте ссылку:' : 'If the button does not work, open this link:',
        'footer' => $ru ? 'Это тестовое письмо из панели администратора.' : 'This is a test message from the admin panel.',
        'settings_label' => $ru ? 'Отключить уведомления по почте' : 'Turn off e-mail notifications',
        'settings_url' => $site . '/profile',
        'site_url' => $site,
    ]);
}
