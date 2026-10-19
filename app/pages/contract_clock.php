<?php
declare(strict_types=1);

// Each participant picks, for their own dashboard, the city whose time they want to see on this contract.
$u = require_login();
$c = load_contract((int)$args[0]);
if (!is_participant($c)) {
    forbidden();
}
$id = (int)$c['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tz = valid_timezone((string)($_POST['timezone'] ?? ''));
    if ($tz) {
        q('REPLACE INTO contract_clocks (user_id, contract_id, timezone) VALUES (?,?,?)', [$u['id'], $id, $tz]);
    } else {
        q('DELETE FROM contract_clocks WHERE user_id = ? AND contract_id = ?', [$u['id'], $id]);
    }
    redirect('/dashboard');
}

$current = valid_timezone((string)val('SELECT timezone FROM contract_clocks WHERE user_id = ? AND contract_id = ?', [$u['id'], $id]));
$common = common_timezones();
$all = DateTimeZone::listIdentifiers();
$now = new DateTimeImmutable('now');

$opt = function (string $z) use ($current, $now): string {
    $off = $now->setTimezone(new DateTimeZone($z))->format('P');
    $city = str_replace('_', ' ', substr(strrchr('/' . $z, '/'), 1));
    return '<option value="' . h($z) . '"' . ($current === $z ? ' selected' : '') . '>' . h($city . ' (' . $z . ', UTC' . $off . ')') . '</option>';
};

$body = function () use ($c, $current, $common, $all, $opt, $id) { ?>
<div class="clock-setup">
  <h2>City / time zone</h2>
  <p class="small muted">Choose where the other side of "<?= h($c['title']) ?>" is, like in a world clock. Only you see this choice.</p>
  <form method="post" action="/contract/<?= $id ?>/clock" data-reload>
    <?= csrf_field() ?>
    <input type="search" class="tz-filter" data-filter-select="tz-select" placeholder="Search city, e.g. London" autocomplete="off">
    <select name="timezone" id="tz-select" size="9" required>
      <optgroup label="Popular"><?php foreach ($common as $z): echo $opt($z); endforeach; ?></optgroup>
      <optgroup label="All cities"><?php foreach ($all as $z): if (!in_array($z, $common, true)) { echo $opt($z); } endforeach; ?></optgroup>
    </select>
    <div class="row between">
      <button class="btn green" type="submit">Save</button>
      <?php if ($current): ?><button class="btn red-outline" type="submit" name="timezone" value="" formnovalidate>Reset</button><?php endif; ?>
    </div>
  </form>
</div>
<?php };

if (isset($_GET['modal'])) {
    $body();
    exit;
}
render('City / time zone', function () use ($body) { echo '<div class="narrow">'; $body(); echo '</div>'; }, ['back' => '/dashboard']);
