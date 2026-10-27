<?php
declare(strict_types=1);

require_once ROOT . '/app/card.php';

/** Common time zones for the profile selector (any valid IANA id is accepted). */
function common_timezones(): array
{
    return ['Europe/Kaliningrad', 'Europe/Moscow', 'Europe/Samara', 'Asia/Yekaterinburg', 'Asia/Omsk', 'Asia/Novosibirsk', 'Asia/Krasnoyarsk', 'Asia/Irkutsk', 'Asia/Yakutsk', 'Asia/Vladivostok', 'Asia/Magadan', 'Asia/Kamchatka',
        'Europe/London', 'Europe/Lisbon', 'Europe/Paris', 'Europe/Berlin', 'Europe/Madrid', 'Europe/Rome', 'Europe/Kyiv', 'Europe/Minsk', 'Europe/Istanbul', 'Asia/Tbilisi', 'Asia/Yerevan', 'Asia/Baku',
        'Asia/Dubai', 'Asia/Tashkent', 'Asia/Almaty', 'Asia/Bishkek', 'Asia/Kolkata', 'Asia/Bangkok', 'Asia/Shanghai', 'Asia/Singapore', 'Asia/Tokyo', 'Asia/Seoul', 'Australia/Sydney', 'Pacific/Auckland',
        'Africa/Cairo', 'Africa/Johannesburg', 'America/Sao_Paulo', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'UTC'];
}

function valid_timezone(?string $tz): ?string
{
    return ($tz && in_array($tz, DateTimeZone::listIdentifiers(), true)) ? $tz : null;
}

function tz_label(string $tz): string
{
    $parts = explode('/', $tz);
    return mb_strtoupper(str_replace('_', ' ', end($parts)));
}

/** Newest published contracts for the dashboard "LIVE" ticker. */
function live_feed_html(): string
{
    $list = rows(listing_select() . " WHERE c.status = 'published' ORDER BY c.created_at DESC, c.id DESC LIMIT 6");
    ob_start();
    foreach ($list as $c) { ?>
<a class="live-item" href="/contract/<?= (int)$c['id'] ?>" data-id="<?= (int)$c['id'] ?>">
  <div class="live-title"><?= h(mb_strimwidth((string)$c['title'], 0, 40, '…')) ?></div>
  <div class="live-desc"><?= h(mb_strimwidth((string)$c['description'], 0, 130, '…')) ?></div>
  <div class="row between"><?php if ($c['category']): ?><span class="chip"><?= h($c['category']) ?></span><?php else: ?><span></span><?php endif; ?><?php if ($c['prepay_required']): ?><span class="pill grey">Prepaid</span><?php endif; ?></div>
  <div class="row between live-price"><span><b><?= money($c['total_price']) ?></b> <small><?= h(cur_code($c)) ?></small></span><span><b><?= (int)$c['steps'] ?></b> <small>STEPS</small></span></div>
</a>
<?php }
    if (!$list) {
        echo '<p class="muted small">No published contracts yet.</p>';
    }
    return (string)ob_get_clean();
}

function gear_icon(int $size = 16): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.9l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.9-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.9.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.9 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.9l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.9.3h.1a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.9-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.9v.1a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/></svg>';
}

/** Default category image as <picture>: mobile image on phones, desktop image elsewhere (either one alone is used for both). */
function category_picture(array $c): string
{
    $d = (string)($c['category_image'] ?? '');
    $m = (string)($c['category_image_mobile'] ?? '');
    if ($d === '' && $m === '') {
        return '';
    }
    $desk = $d !== '' ? $d : $m;
    $mob = $m !== '' ? $m : $d;
    $fit = ($c['category_fit'] ?? 'cover') === 'contain' ? 'contain' : 'cover';
    return '<picture class="cat-pic fit-' . $fit . '"><source media="(max-width:800px)" srcset="/uploads/' . h($mob) . '"><img src="/uploads/' . h($desk) . '" alt=""></picture>';
}

/**
 * Published contracts that got no Contractor within one month leave the listing and go to the
 * author's archive (as stated on the Prices page). Runs at most every 10 minutes, on page loads.
 */
function archive_stale_contracts(): void
{
    $last = (int)val("SELECT v FROM site_settings WHERE k = 'last_stale_archive'");
    if ($last > time() - 600) {
        return;
    }
    q("REPLACE INTO site_settings (k, v) VALUES ('last_stale_archive', ?)", [(string)time()]);
    $ids = array_column(rows("SELECT id FROM contracts WHERE status = 'published' AND COALESCE(published_at, created_at) < (NOW() - INTERVAL 1 MONTH)"), 'id');
    if (!$ids) {
        return;
    }
    $in = implode(',', array_map('intval', $ids));
    q("DELETE FROM comments WHERE scope = 'offer' AND offer_id IN (SELECT id FROM offers WHERE contract_id IN ($in) AND status = 'pending')");
    q("DELETE FROM offers WHERE contract_id IN ($in) AND status = 'pending'");
    q("UPDATE contracts SET status = 'terminated' WHERE id IN ($in) AND status = 'published'");
}
