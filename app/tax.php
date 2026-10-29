<?php
declare(strict_types=1);

/*
 * Tax logic for invoices (Russia).
 *
 * The amount stored on an invoice (invoices.amount) is the price of the To Do exactly as it was agreed. Net / VAT /
 * total are never stored while the invoice is open: they are calculated from that price and the tax settings every
 * time they are shown, mailed or put in the PDF, so a change in the admin panel (Admin -> Taxes) recalculates every
 * unpaid invoice at once. When the contractor confirms the payment the numbers and the legal text are frozen in
 * invoices.tax_json, so later changes of the rates never rewrite a paid invoice.
 *
 * How each admin setting works (all stored as one JSON in site_settings.tax_config):
 *
 *  tax_mode                 OSN      - general system, VAT is charged.
 *                           USN_6 / USN_15 - simplified system, the seller is not a VAT payer: VAT is 0 and the
 *                                      invoice carries the "Without VAT" legal basis (usn_description).
 *                           other    - custom regime. If exemption_rule is filled the invoice is "Without VAT" with
 *                                      that reference (e.g. Article 149 of the Tax Code); if it is empty VAT is
 *                                      charged like in OSN.
 *  default_vat_rate         VAT percent used when no override matches (20, 10, 0 or any other number - rates are
 *                           data, so a future change such as 22% is only a new value here, not a code change).
 *  vat_included_by_default  true:  the To Do price already contains VAT   VAT = Total * Rate / (100 + Rate), Net = Total - VAT
 *                           false: the To Do price is without VAT         VAT = Net * Rate / 100,           Total = Net + VAT
 *  item_level_vat_override  list of rules "text -> rate". If the task name or the contract category contains the text
 *                           (case-insensitive) the rule's rate is used instead of default_vat_rate. First match wins.
 *  usn_description          text of the "Without VAT" line for USN_6 / USN_15 (placeholders {article}, {mode}); has a
 *                           Russian twin usn_description_ru.
 *  exemption_rule           Tax Code reference for VAT-exempt operations in the "other" regime; Russian twin
 *                           exemption_rule_ru.
 *  rounding_mode            to_cent (kopecks, half-up) | to_ruble (whole rubles) | no_rounding. VAT is rounded
 *                           first and the other part is derived from it, so Net + VAT always equals Total exactly.
 *  currency                 currency code used when an invoice has none (default RUB).
 *  locale_settings          decimal separator, thousands separator and symbol position for invoice numbers.
 */

const TAX_MODES = ['OSN' => 'OSN (general system, VAT)', 'USN_6' => 'USN 6% (no VAT)', 'USN_15' => 'USN 15% (no VAT)', 'other' => 'Other / custom'];
const TAX_ROUNDING = ['to_cent' => 'To the cent (kopeck)', 'to_ruble' => 'To the ruble', 'no_rounding' => 'No rounding'];

function tax_defaults(): array
{
    return [
        'tax_mode' => 'other',
        'default_vat_rate' => 0.0,
        'vat_included_by_default' => false,
        'item_level_vat_override' => [],
        'usn_description' => 'Without VAT, based on Article 346.11 of the Tax Code of the Russian Federation',
        'usn_description_ru' => 'Без НДС, на основании статьи 346.11 Налогового кодекса РФ',
        'exemption_rule' => '',
        'exemption_rule_ru' => '',
        'rounding_mode' => 'to_cent',
        'currency' => 'RUB',
        'locale_settings' => ['decimal' => ',', 'thousands' => "\u{00A0}", 'symbol_position' => 'after'],
    ];
}

function tax_config(bool $fresh = false): array
{
    static $cfg = null;
    if ($cfg !== null && !$fresh) {
        return $cfg;
    }
    $cfg = tax_defaults();
    $saved = json_decode((string)(val("SELECT v FROM site_settings WHERE k = 'tax_config'") ?? ''), true);
    if (is_array($saved)) {
        foreach ($saved as $k => $v) {
            if (array_key_exists($k, $cfg)) {
                $cfg[$k] = $k === 'locale_settings' && is_array($v) ? array_merge($cfg[$k], $v) : $v;
            }
        }
    }
    return $cfg;
}

function tax_round(float $v, string $mode): float
{
    return match ($mode) {
        'to_ruble' => round($v, 0, PHP_ROUND_HALF_UP),
        'no_rounding' => round($v, 8),
        default => round($v, 2, PHP_ROUND_HALF_UP),
    };
}

/** VAT rate for one item: the first override rule that matches the task name or the category, else the default. */
function tax_item_rate(array $cfg, string $taskName, string $categoryName): float
{
    foreach ((array)$cfg['item_level_vat_override'] as $rule) {
        $m = trim((string)($rule['match'] ?? ''));
        if ($m !== '' && is_numeric($rule['rate'] ?? null)
            && (mb_stripos($taskName, $m) !== false || mb_stripos($categoryName, $m) !== false)) {
            return (float)$rule['rate'];
        }
    }
    return (float)$cfg['default_vat_rate'];
}

/** The "Without VAT, ..." sentence in both languages for the current regime, or null when VAT is charged. */
function tax_no_vat_notes(array $cfg): ?array
{
    $mode = (string)$cfg['tax_mode'];
    if ($mode === 'USN_6' || $mode === 'USN_15') {
        $fill = fn(string $t) => str_replace(['{article}', '{mode}'], ['346.11', $mode === 'USN_6' ? 'USN 6%' : 'USN 15%'], $t);
        return ['en' => $fill((string)$cfg['usn_description']), 'ru' => $fill((string)($cfg['usn_description_ru'] ?: $cfg['usn_description']))];
    }
    if ($mode === 'other' && trim((string)$cfg['exemption_rule']) !== '') {
        $en = trim((string)$cfg['exemption_rule']);
        $ru = trim((string)($cfg['exemption_rule_ru'] ?: $en));
        return ['en' => stripos($en, 'without vat') === 0 ? $en : 'Without VAT, ' . $en, 'ru' => stripos($ru, 'без ндс') === 0 ? $ru : 'Без НДС, ' . $ru];
    }
    return null;
}

/**
 * Net / VAT / total for a price. $rate: the item's VAT percent (ignored by the no-VAT regimes).
 * Returns net, vat, total, rate, included, no_vat, note_en, note_ru.
 */
function tax_calc(float $amount, float $rate, array $cfg): array
{
    $mode = (string)$cfg['rounding_mode'];
    $notes = tax_no_vat_notes($cfg);
    if ($notes) {
        $t = tax_round($amount, $mode);
        return ['net' => $t, 'vat' => 0.0, 'total' => $t, 'rate' => 0.0, 'included' => false, 'no_vat' => true, 'note_en' => $notes['en'], 'note_ru' => $notes['ru']];
    }
    $included = (bool)$cfg['vat_included_by_default'];
    if ($included) {
        $total = tax_round($amount, $mode);
        $vat = tax_round($total * $rate / (100 + $rate), $mode);
        $net = tax_round($total - $vat, $mode);
    } else {
        $net = tax_round($amount, $mode);
        $vat = tax_round($net * $rate / 100, $mode);
        $total = tax_round($net + $vat, $mode);
    }
    return ['net' => $net, 'vat' => $vat, 'total' => $total, 'rate' => $rate, 'included' => $included, 'no_vat' => false, 'note_en' => '', 'note_ru' => ''];
}

/**
 * Site settings with the issuer's own regime on top. The invoice is issued by the contractor, so the contractor's
 * profile (users.tax_json: tax_mode, default_vat_rate, vat_included_by_default, exemption_rule[_ru]) overrides the
 * site-wide admin defaults; every key the contractor left empty falls back to the admin value. Item rules, rounding,
 * the USN texts, currency and number format always stay site-wide.
 */
function tax_config_for_user(?string $userJson): array
{
    $cfg = tax_config();
    $u = json_decode((string)$userJson, true);
    if (!is_array($u)) {
        return $cfg;
    }
    if (isset(TAX_MODES[$u['tax_mode'] ?? ''])) {
        $cfg['tax_mode'] = $u['tax_mode'];
    }
    if (isset($u['default_vat_rate']) && is_numeric($u['default_vat_rate'])) {
        $cfg['default_vat_rate'] = (float)$u['default_vat_rate'];
    }
    if (isset($u['vat_included_by_default']) && $u['vat_included_by_default'] !== '') {
        $cfg['vat_included_by_default'] = (bool)$u['vat_included_by_default'];
    }
    foreach (['exemption_rule', 'exemption_rule_ru'] as $k) {
        if (trim((string)($u[$k] ?? '')) !== '') {
            $cfg[$k] = trim((string)$u[$k]);
        }
    }
    return $cfg;
}

/** Validates the profile form fields; returns the JSON to store (null = use the site defaults) or an error text. */
function tax_user_json_from_post(array $p): array
{
    $mode = (string)($p['tax_mode'] ?? '');
    $rate = str_replace(',', '.', trim((string)($p['tax_vat_rate'] ?? '')));
    $inc = (string)($p['tax_vat_included'] ?? '');
    $rule = trim((string)($p['tax_exemption'] ?? ''));
    if ($mode !== '' && !isset(TAX_MODES[$mode])) {
        return [null, 'Choose a tax regime from the list.'];
    }
    if ($rate !== '' && (!is_numeric($rate) || (float)$rate < 0 || (float)$rate > 100)) {
        return [null, 'The VAT rate must be a number from 0 to 100.'];
    }
    if ($mode === '' && $rate === '' && $inc === '' && $rule === '') {
        return [null, null];
    }
    $out = ['tax_mode' => $mode, 'vat_included_by_default' => $inc === '' ? '' : ($inc === '1'), 'exemption_rule' => mb_substr($rule, 0, 400)];
    if ($rate !== '') {
        $out['default_vat_rate'] = (float)$rate;
    }
    return [json_encode($out, JSON_UNESCAPED_UNICODE), null];
}

/** Tax numbers of an invoice row: the frozen ones when it is paid, else calculated now from the current settings. */
function tax_for_invoice(array $inv): array
{
    if (!empty($inv['tax_json'])) {
        $frozen = json_decode((string)$inv['tax_json'], true);
        if (is_array($frozen) && isset($frozen['total'])) {
            return $frozen;
        }
    }
    $cfg = tax_config_for_user(!empty($inv['payee_id']) ? (string)(val('SELECT tax_json FROM users WHERE id = ?', [$inv['payee_id']]) ?? '') : null);
    $task = (string)($inv['todo_name'] ?? '');
    if (!array_key_exists('todo_name', $inv) && !empty($inv['todo_id'])) {
        $task = (string)(val('SELECT name FROM todos WHERE id = ?', [$inv['todo_id']]) ?? '');
    }
    $cat = '';
    if ($cfg['item_level_vat_override']) {
        $cat = (string)(val('SELECT ca.name FROM contracts c JOIN categories ca ON ca.id = c.category_id WHERE c.id = ?', [$inv['contract_id']]) ?? '');
    }
    return tax_calc((float)$inv['amount'], tax_item_rate($cfg, $task, $cat), $cfg);
}

/** Number in the configured locale, with the currency symbol before or after it. */
function tax_money(float $n, ?string $currency = null, bool $symbol = true): string
{
    $cfg = tax_config();
    $loc = $cfg['locale_settings'];
    $dec = $cfg['rounding_mode'] === 'to_ruble' ? 0 : 2;
    $s = number_format($n, $dec, (string)$loc['decimal'], (string)$loc['thousands']);
    if (!$symbol) {
        return $s;
    }
    $cur = $currency ?: (string)$cfg['currency'];
    $sym = CURRENCIES[$cur][0] ?? $cur;
    return ($loc['symbol_position'] ?? 'after') === 'before' ? $sym . "\u{00A0}" . $s : $s . "\u{00A0}" . $sym;
}

/** Validates and saves the admin form; returns an error text or null. */
function tax_save_from_post(array $p): ?string
{
    $d = tax_defaults();
    $mode = (string)($p['tax_mode'] ?? '');
    $round = (string)($p['rounding_mode'] ?? '');
    $rate = str_replace(',', '.', trim((string)($p['default_vat_rate'] ?? '')));
    $cur = strtoupper(trim((string)($p['currency'] ?? 'RUB')));
    if (!isset(TAX_MODES[$mode]) || !isset(TAX_ROUNDING[$round])) {
        return 'Choose the tax mode and the rounding mode.';
    }
    if (!is_numeric($rate) || (float)$rate < 0 || (float)$rate > 100) {
        return 'The VAT rate must be a number from 0 to 100.';
    }
    if (!preg_match('/^[A-Z]{3}$/', $cur)) {
        return 'Currency must be a 3-letter code such as RUB.';
    }
    $rules = [];
    foreach ((array)($p['override'] ?? []) as $r) {
        $m = trim((string)($r['match'] ?? ''));
        $rt = str_replace(',', '.', trim((string)($r['rate'] ?? '')));
        if ($m === '' && $rt === '') {
            continue;
        }
        if ($m === '' || !is_numeric($rt) || (float)$rt < 0 || (float)$rt > 100) {
            return 'Each item rule needs a text and a rate from 0 to 100.';
        }
        $rules[] = ['match' => mb_substr($m, 0, 120), 'rate' => (float)$rt];
    }
    $pos = ($p['symbol_position'] ?? 'after') === 'before' ? 'before' : 'after';
    $cfg = [
        'tax_mode' => $mode,
        'default_vat_rate' => (float)$rate,
        'vat_included_by_default' => !empty($p['vat_included_by_default']),
        'item_level_vat_override' => $rules,
        'usn_description' => mb_substr(trim((string)($p['usn_description'] ?? '')) ?: $d['usn_description'], 0, 400),
        'usn_description_ru' => mb_substr(trim((string)($p['usn_description_ru'] ?? '')) ?: $d['usn_description_ru'], 0, 400),
        'exemption_rule' => mb_substr(trim((string)($p['exemption_rule'] ?? '')), 0, 400),
        'exemption_rule_ru' => mb_substr(trim((string)($p['exemption_rule_ru'] ?? '')), 0, 400),
        'rounding_mode' => $round,
        'currency' => $cur,
        'locale_settings' => [
            'decimal' => mb_substr((string)($p['decimal'] ?? ',') ?: ',', 0, 2),
            'thousands' => mb_substr((string)($p['thousands'] ?? '') === '' ? "\u{00A0}" : (string)$p['thousands'], 0, 2),
            'symbol_position' => $pos,
        ],
    ];
    q("REPLACE INTO site_settings (k, v) VALUES ('tax_config', ?)", [json_encode($cfg, JSON_UNESCAPED_UNICODE)]);
    tax_config(true);
    return null;
}

/** 20.0 -> "20", 7.5 -> "7.5"; '' stays empty. */
function tax_num($v): string
{
    if ($v === '' || $v === null) {
        return '';
    }
    $s = rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}
