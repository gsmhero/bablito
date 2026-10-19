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
