<?php
declare(strict_types=1);

$u = require_login();
$extra = json_decode((string)$u['extra_fields'], true);
$extra = is_array($extra) ? $extra : [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['contact_email'] ?? ''));
    if ($name === '' || mb_strlen($name) > 120) {
        $error = 'Enter your name.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Contact email is invalid.';
    } elseif ([$taxJson, $taxErr] = tax_user_json_from_post($_POST) and $taxErr !== null) {
        $error = $taxErr;
    } elseif (($perr = profile_save_values((int)$u['id'], sections_for(profile_structure(), types_from_post($_POST['types'] ?? [])), (array)($_POST['pf'] ?? []))) !== null) {
        $error = $perr;
    } else {
        $fields = [];
        foreach ((array)($_POST['extra'] ?? []) as $e) {
            $k = trim((string)($e['label'] ?? ''));
            $v = trim((string)($e['value'] ?? ''));
            if ($k !== '' && count($fields) < 10) {
                $fields[] = ['label' => mb_substr($k, 0, 60), 'value' => mb_substr($v, 0, 190), 'public' => empty($e['public']) ? 0 : 1];
            }
        }
        q('UPDATE users SET name=?, about=?, contact_phone=?, contact_email=?, show_name=?, show_phone=?, show_email=?, extra_fields=?, timezone=?, user_types=?, notify_email=?, tax_json=? WHERE id=?', [
            $name, mb_substr(trim((string)($_POST['about'] ?? '')), 0, 4000), mb_substr(trim((string)($_POST['contact_phone'] ?? '')), 0, 60), $email,
            empty($_POST['show_name']) ? 0 : 1, empty($_POST['show_phone']) ? 0 : 1, empty($_POST['show_email']) ? 0 : 1,
            json_encode($fields, JSON_UNESCAPED_UNICODE), valid_timezone((string)($_POST['timezone'] ?? '')), implode(',', types_from_post($_POST['types'] ?? [])), empty($_POST['notify_email']) ? 0 : 1, $taxJson, $u['id'],
        ]);
        flash('Profile saved.');
        redirect('/profile');
    }
    $u = array_merge($u, ['name' => $name]);
}

// Every section is in the form; the ones for statuses that are not ticked are hidden and switched on
// as soon as the user ticks the status (also after a failed save, so required fields stay visible).
$curTypes = $_SERVER['REQUEST_METHOD'] === 'POST' ? types_from_post($_POST['types'] ?? []) : user_types($u);
$struct = profile_structure();
$pvals = profile_values((int)$u['id']);
$posted = $_SERVER['REQUEST_METHOD'] === 'POST' ? (array)($_POST['pf'] ?? []) : [];

render('Edit profile', function () use ($u, $extra, $error, $struct, $pvals, $curTypes, $posted) { ?>
<form method="post" class="narrow card-form wide">
  <?= csrf_field() ?>
  <div class="row between"><h1>Edit Profile</h1><span><a class="small" href="/user/<?= (int)$u['id'] ?>">View public profile</a> <button class="btn small" type="submit">Save Profile</button></span></div>
  <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
  <div class="field-row"><label>Your name <input name="name" value="<?= h($u['name']) ?>" maxlength="120" required></label>
    <label class="check"><input type="checkbox" name="show_name" value="1" <?= $u['show_name'] ? 'checked' : '' ?>> Show in Public</label></div>
  <div class="small"><b>I act as</b> <span class="muted">(you can choose several)</span><?= party_checkboxes('types', $curTypes) ?></div>
  <label class="check"><input type="checkbox" name="notify_email" value="1" <?= (int)($u['notify_email'] ?? 1) === 1 ? 'checked' : '' ?>> Receive e-mail notifications about my contracts</label>
  <label>Time zone <select name="timezone"><option value="">Not set</option><?php $cur = valid_timezone($u['timezone'] ?? null); $zones = common_timezones(); if ($cur && !in_array($cur, $zones, true)) { $zones[] = $cur; } foreach ($zones as $z): ?><option value="<?= h($z) ?>" <?= $cur === $z ? 'selected' : '' ?>><?= h($z) ?></option><?php endforeach; ?></select></label>
  <div class="field-row"><label>Contact Phone <input name="contact_phone" value="<?= h($u['contact_phone']) ?>" maxlength="60"></label>
    <label class="check"><input type="checkbox" name="show_phone" value="1" <?= $u['show_phone'] ? 'checked' : '' ?>> Show in Public</label></div>
  <div class="field-row"><label>Contact Email <input type="email" name="contact_email" value="<?= h($u['contact_email']) ?>" maxlength="190"></label>
    <label class="check"><input type="checkbox" name="show_email" value="1" <?= $u['show_email'] ? 'checked' : '' ?>> Show in Public</label></div>
  <div id="extra-fields">
    <?php foreach ($extra as $i => $e): ?>
      <div class="field-row">
        <label><?= h($e['label']) ?><input type="hidden" name="extra[<?= $i ?>][label]" value="<?= h($e['label']) ?>"><input name="extra[<?= $i ?>][value]" value="<?= h($e['value']) ?>" maxlength="190"></label>
        <label class="check"><input type="checkbox" name="extra[<?= $i ?>][public]" value="1" <?= !empty($e['public']) ? 'checked' : '' ?>> Show in Public</label>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="field-row"><label>Field name <input id="new-field-name" maxlength="60"></label><button type="button" class="btn small" id="add-field">Add Field</button></div>
  <?php foreach ($struct as $s): if (!$s['fields']) { continue; }
      $on = in_array($s['type'] ?? 'personal', $curTypes, true); ?>
  <div class="pf-section" data-type="<?= h($s['type'] ?? 'personal') ?>" <?= $on ? '' : 'hidden' ?>>
    <h3 class="pf-title"><?= h($s['title']) ?> <span class="pill party-pill"><?= h(party_label($s['type'] ?? null)) ?></span></h3>
    <?php foreach ($s['fields'] as $f):
        $pv = $pvals[(int)$f['id']] ?? null;
        if (isset($posted[$f['id']])) {
            $pv = ['value' => (string)($posted[$f['id']]['value'] ?? ''), 'is_public' => empty($posted[$f['id']]['public']) ? 0 : 1];
        } ?>
      <div class="field-row">
        <label><?= h($f['label']) ?><?= $f['required'] ? ' *' : '' ?> <?= profile_input_html($f, $pv ? (string)$pv['value'] : '') ?></label>
        <label class="check"><input type="checkbox" name="pf[<?= (int)$f['id'] ?>][public]" value="1" <?= ($pv ? $pv['is_public'] : $f['default_public']) ? 'checked' : '' ?>> Show in Public</label>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <?php $tu = json_decode((string)($u['tax_json'] ?? ''), true); $tu = is_array($tu) ? $tu : [];
      if ($_SERVER['REQUEST_METHOD'] === 'POST') { $tu = ['tax_mode' => (string)($_POST['tax_mode'] ?? ''), 'default_vat_rate' => (string)($_POST['tax_vat_rate'] ?? ''), 'vat_included_by_default' => ($_POST['tax_vat_included'] ?? ''), 'exemption_rule' => (string)($_POST['tax_exemption'] ?? '')]; }
      $tv = $tu['vat_included_by_default'] ?? ''; $tinc = $tv === '' ? '' : ($tv === true || $tv === '1' || $tv === 1 ? '1' : '0'); ?>
  <div class="pf-section" id="tax-section">
    <h3 class="pf-title">Tax regime for invoices you issue</h3>
    <p class="small muted">Used in the invoices where you are the Contractor. Leave "Site default" if you are not sure; the site then uses its general tax settings.</p>
    <div class="field-row">
      <label>Tax regime
        <select name="tax_mode"><option value="">Site default</option><?php foreach (TAX_MODES as $k => $l): ?><option value="<?= h($k) ?>" <?= ($tu['tax_mode'] ?? '') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
      <label>VAT rate, % <input name="tax_vat_rate" value="<?= h(tax_num($tu['default_vat_rate'] ?? '')) ?>" inputmode="decimal" list="tax-rates" placeholder="Site default"></label>
      <datalist id="tax-rates"><option value="20"><option value="10"><option value="0"></datalist>
    </div>
    <label>My prices
      <select name="tax_vat_included"><option value="">Site default</option><option value="1" <?= $tinc === '1' ? 'selected' : '' ?>>include VAT</option><option value="0" <?= $tinc === '0' ? 'selected' : '' ?>>are without VAT (VAT is added)</option></select></label>
    <label>VAT exemption reference (regime "Other"), e.g. Article 149 of the Tax Code <input name="tax_exemption" value="<?= h($tu['exemption_rule'] ?? '') ?>" maxlength="400"></label>
  </div>
  <label>About me <textarea name="about" rows="4"><?= h($u['about']) ?></textarea></label>
</form>
<?php });
