<?php
declare(strict_types=1);

// Site language: English (source text) or Russian. The page is built in English and, for Russian,
// every visible text, placeholder, tooltip and confirm() text is translated on the way out
// (see i18n_filter). Texts that are not in the dictionary stay unchanged.

const SUPPORTED_LANGS = ['en', 'ru'];

function current_lang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $c = (string)($_COOKIE['lang'] ?? '');
    if (in_array($c, SUPPORTED_LANGS, true)) {
        return $lang = $c;
    }
    $s = (string)($_SESSION['lang'] ?? '');
    if (in_array($s, SUPPORTED_LANGS, true)) {
        return $lang = $s;
    }
    $al = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    return $lang = (preg_match('/(^|,)\s*ru\b/', $al) ? 'ru' : 'en');
}

function set_lang_cookie(string $lang): void
{
    $_SESSION['lang'] = $lang; // also kept in the session: a frame may not send cookies
    setcookie('lang', $lang, [
        'expires' => time() + 365 * 86400,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => false,
        'samesite' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'None' : 'Lax',
    ]);
}

function i18n_dict(): array
{
    static $d = null;
    return $d ??= (require ROOT . '/app/lang_ru.php');
}

/** Normalised key: &nbsp; and &amp; decoded, whitespace collapsed. */
function i18n_key(string $s): string
{
    return trim((string)preg_replace('/\s+/', ' ', str_replace(['&nbsp;', '&amp;'], [' ', '&'], $s)));
}

/** Translation of a text, or the text itself when unknown. */
function i18n_lookup(string $text): string
{
    $key = i18n_key($text);
    if ($key === '') {
        return $text;
    }
    $d = i18n_dict();
    if (isset($d['exact'][$key])) {
        return $d['exact'][$key];
    }
    foreach ($d['patterns'] as [$re, $rep]) {
        if (preg_match($re, $key, $m)) {
            return is_callable($rep) ? (string)$rep($m) : (string)preg_replace($re, $rep, $key);
        }
    }
    return $text;
}

/** "Company → Personal" with translated status names. */
function i18n_party_pair(string $pair): string
{
    return implode(' → ', array_map('i18n_lookup', explode(' → ', $pair)));
}

function i18n_node(string $raw): string
{
    if (!preg_match('/^(\s*)(.*?)(\s*)$/s', $raw, $m) || $m[2] === '') {
        return $raw;
    }
    $t = i18n_lookup($m[2]);
    if ($t === $m[2]) {
        return $raw;
    }
    $lead = ($t[0] === '.' || $t[0] === ',') ? '' : $m[1];
    return $lead . $t . $m[3];
}

/** Output filter: translates text nodes and attributes of the finished HTML page. */
function i18n_filter(string $html): string
{
    foreach (headers_list() as $h) {
        if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) {
            return $html;
        }
    }
    $parts = preg_split('~(<script\b.*?</script>|<style\b.*?</style>|<textarea\b.*?</textarea>|<!--.*?-->)~si', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => $p) {
        if ($i % 2 === 1) {
            // protected block: only the placeholder of a textarea is translated
            if (stripos($p, '<textarea') === 0) {
                $parts[$i] = preg_replace_callback('/^(<textarea\b[^>]*>)/i', fn($m) => i18n_attrs($m[1]), $p);
            }
            continue;
        }
        $p = preg_replace_callback('/>([^<>]+)</', fn($m) => '>' . i18n_node($m[1]) . '<', $p);
        $p = i18n_attrs($p);
        $parts[$i] = $p;
    }
    return implode('', $parts);
}

function i18n_attrs(string $html): string
{
    $html = preg_replace_callback('/\b(placeholder|title|aria-label|alt|label)="([^"]*)"/', function ($m) {
        $t = i18n_lookup(html_entity_decode($m[2], ENT_QUOTES));
        return $t === $m[2] ? $m[0] : $m[1] . '="' . str_replace('"', '&quot;', $t) . '"';
    }, $html);
    // confirm('...') in onclick / onsubmit attributes
    return (string)preg_replace_callback('/confirm\(\'((?:[^\'\\\\]|\\\\.)*)\'\)/', function ($m) {
        $src = $m[1];
        $t = i18n_lookup($src);
        return $t === $src ? $m[0] : "confirm('" . str_replace("'", '\\\'', $t) . "')";
    }, $html);
}

/** JSON dictionary for the browser scripts. */
function i18n_js_json(): string
{
    return current_lang() === 'ru' ? json_encode(i18n_dict()['js'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) : '{}';
}
