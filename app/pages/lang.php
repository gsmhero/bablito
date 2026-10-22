<?php
declare(strict_types=1);

// Language switch: remembers the choice in a cookie and returns to the page the visitor came from.
$lang = (string)$args[0];
if (in_array($lang, SUPPORTED_LANGS, true)) {
    set_lang_cookie($lang);
}
$ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
$to = '/';
if ($ref !== '') {
    $p = parse_url($ref);
    $host = (string)($p['host'] ?? '');
    if ($host === '' || strcasecmp($host, (string)($_SERVER['HTTP_HOST'] ?? '')) === 0) {
        $to = (string)($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }
}
redirect(safe_local_path($to));
