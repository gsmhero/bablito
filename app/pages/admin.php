<?php
declare(strict_types=1);

require_once ROOT . '/app/card.php';
$me = require_admin();
$tab = $args[0] ?? 'users';
$self = '/admin/' . $tab;

if ($tab === 'settings' && isset($_GET['preview'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo email_sample_html($_GET['preview'] === 'ru' ? 'ru' : 'en');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string)($_POST['do'] ?? '');
    switch ($tab) {
        case 'users':
            $uid = (int)($_POST['id'] ?? 0);
            if ($uid && $uid !== (int)$me['id']) {
                if ($do === 'block') {
                    q('UPDATE users SET blocked = 1 WHERE id = ?', [$uid]);
                } elseif ($do === 'unblock') {
                    q('UPDATE users SET blocked = 0 WHERE id = ?', [$uid]);
                }
                flash('User updated.');
            }
            break;
        case 'contracts':
            $cid = (int)($_POST['id'] ?? 0);
            if ($do === 'unpublish') {
                q("UPDATE contracts SET status = 'draft' WHERE id = ? AND status = 'published'", [$cid]);
                flash('Contract moved to drafts.');
            }
            break;
        case 'home':
            q('REPLACE INTO site_settings (k, v) VALUES (?, ?)', ['home_title', trim((string)($_POST['home_title'] ?? ''))]);
            if ($do === 'add_block') {
                q('INSERT INTO home_blocks (position, title, body, color) VALUES ((SELECT COALESCE(MAX(position),0)+1 FROM (SELECT position FROM home_blocks) p), ?, ?, ?)', ['New block', '', 'green']);
            } elseif (str_starts_with($do, 'del_')) {
                q('DELETE FROM home_blocks WHERE id = ?', [(int)substr($do, 4)]);
            } else {
                foreach ((array)($_POST['blocks'] ?? []) as $bid => $b) {
                    $color = ($b['color'] ?? '') === 'blue' ? 'blue' : 'green';
                    q('UPDATE home_blocks SET title = ?, body = ?, color = ?, position = ? WHERE id = ?', [mb_substr(trim((string)($b['title'] ?? '')), 0, 190), trim((string)($b['body'] ?? '')), $color, (int)($b['position'] ?? 0), (int)$bid]);
                }
            }
            flash('Homepage saved.');
            break;
        case 'categories':
            $cid = (int)($_POST['id'] ?? 0);
            if ($do === 'add') {
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name !== '' && mb_strlen($name) <= 80 && !val('SELECT id FROM categories WHERE name = ?', [$name])) {
                    q('INSERT INTO categories (name, name_ru) VALUES (?, ?)', [$name, mb_substr(trim((string)($_POST['name_ru'] ?? '')), 0, 80) ?: null]);
                    flash('Category added.');
                } else {
                    flash('Enter a unique category name (up to 80 characters).', 'err');
                }
            } elseif ($cid && ($do === 'delete' || $do === 'remove_image' || $do === 'remove_image_mobile')) {
                // each category has a desktop image and an optional mobile image
                $cols = $do === 'delete' ? ['image', 'image_mobile'] : [$do === 'remove_image' ? 'image' : 'image_mobile'];
                foreach ($cols as $col) {
                    $old = (string)val("SELECT $col FROM categories WHERE id = ?", [$cid]);
                    if ($old !== '') {
                        @unlink(ROOT . '/uploads/' . $old);
                    }
                }
                if ($do === 'delete') {
                    q('DELETE FROM categories WHERE id = ?', [$cid]);
                    flash('Category deleted. Its contracts stay without a category.');
                } else {
                    q('UPDATE categories SET ' . $cols[0] . ' = NULL WHERE id = ?', [$cid]);
                    flash('Default image removed.');
                }
            } elseif ($cid && $do === 'save') {
                $name = trim((string)($_POST['name'] ?? ''));
                $dup = $name !== '' ? val('SELECT id FROM categories WHERE name = ? AND id <> ?', [$name, $cid]) : 1;
                if ($name === '' || mb_strlen($name) > 80 || $dup) {
                    flash('Enter a unique category name (up to 80 characters).', 'err');
                    break;
                }
                q('UPDATE categories SET name = ?, name_ru = ?, image_fit = ? WHERE id = ?', [$name, mb_substr(trim((string)($_POST['name_ru'] ?? '')), 0, 80) ?: null, ($_POST['image_fit'] ?? '') === 'contain' ? 'contain' : 'cover', $cid]);
                foreach (['image' => 'image', 'image_mobile' => 'image_mobile'] as $field => $col) {
                    $f = $_FILES[$field] ?? ['error' => UPLOAD_ERR_NO_FILE];
                    if ($f['error'] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $res = store_upload($f, 'image');
                    if (is_string($res)) {
                        flash($res, 'err');
                        redirect($self);
                    }
                    $old = (string)val("SELECT $col FROM categories WHERE id = ?", [$cid]);
                    if ($old !== '') {
                        @unlink(ROOT . '/uploads/' . $old);
                    }
                    q("UPDATE categories SET $col = ? WHERE id = ?", [$res[1], $cid]);
                }
                flash('Category saved.');
            }
            break;
        case 'profile':
            if ($do === 'add_section') {
                $title = trim((string)($_POST['new_section'] ?? ''));
                if ($title !== '' && mb_strlen($title) <= 120) {
                    q('INSERT INTO profile_sections (title, type, position) VALUES (?, ?, (SELECT COALESCE(MAX(p.position), 0) + 1 FROM (SELECT position FROM profile_sections) p))', [$title, isset(PARTY_TYPES[$_POST['new_type'] ?? '']) ? $_POST['new_type'] : 'personal']);
                    flash('Section added.');
                } else {
                    flash('Enter a section title (up to 120 characters).', 'err');
                }
            } elseif (str_starts_with($do, 'del_section_')) {
                q('DELETE FROM profile_sections WHERE id = ?', [(int)substr($do, 12)]);
                flash('Section deleted together with its fields and the values users entered.');
            } elseif (str_starts_with($do, 'del_field_')) {
                q('DELETE FROM profile_fields WHERE id = ?', [(int)substr($do, 10)]);
                flash('Field deleted.');
            } else {
                foreach ((array)($_POST['sections'] ?? []) as $sid => $s) {
                    q('UPDATE profile_sections SET title = ?, type = ?, position = ? WHERE id = ?', [mb_substr(trim((string)($s['title'] ?? '')), 0, 120) ?: 'Section', isset(PARTY_TYPES[$s['type'] ?? '']) ? $s['type'] : 'personal', (int)($s['position'] ?? 0), (int)$sid]);
                }
                foreach ((array)($_POST['fields'] ?? []) as $fid => $f) {
                    $type = isset(PROFILE_FIELD_TYPES[$f['type'] ?? '']) ? $f['type'] : 'text';
                    $label = mb_substr(trim((string)($f['label'] ?? '')), 0, 120);
                    if ($label === '') {
                        continue;
                    }
                    q('UPDATE profile_fields SET label = ?, type = ?, options = ?, required = ?, default_public = ?, position = ? WHERE id = ?',
                        [$label, $type, trim((string)($f['options'] ?? '')), empty($f['required']) ? 0 : 1, empty($f['public']) ? 0 : 1, (int)($f['position'] ?? 0), (int)$fid]);
                }
                $added = 0;
                foreach ((array)($_POST['nf'] ?? []) as $sid => $f) {
                    $label = mb_substr(trim((string)($f['label'] ?? '')), 0, 120);
                    if ($label === '' || !val('SELECT id FROM profile_sections WHERE id = ?', [(int)$sid])) {
                        continue;
                    }
                    $type = isset(PROFILE_FIELD_TYPES[$f['type'] ?? '']) ? $f['type'] : 'text';
                    q('INSERT INTO profile_fields (section_id, label, type, options, required, default_public, position) VALUES (?,?,?,?,?,?, (SELECT COALESCE(MAX(p.position), 0) + 1 FROM (SELECT position FROM profile_fields) p))',
                        [(int)$sid, $label, $type, trim((string)($f['options'] ?? '')), empty($f['required']) ? 0 : 1, empty($f['public']) ? 0 : 1]);
                    $added++;
                }
                flash($added ? 'Saved. ' . $added . ' field(s) added.' : 'Saved.');
            }
            break;
        case 'invoices':
            admin_invoices_post($do, $self);
            break;
        case 'settings':
            if ($do === 'test_mail') {
                $err = smtp_send((string)$me['email'], 'bablito test e-mail', "This is a test message from bablito.\nIf you can read it, e-mail notifications work.", email_sample_html(user_lang($me)));
                flash($err === null ? 'Test e-mail sent to ' . $me['email'] . '.' : 'The test e-mail failed: ' . $err, $err === null ? 'ok' : 'err');
            } else {
                $price = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string)($_POST['service_price'] ?? '')));
                $cur = (string)($_POST['service_currency'] ?? 'RUB');
                if (!is_numeric($price) || (float)$price < 0 || (float)$price > 1000000 || !isset(CURRENCIES[$cur])) {
                    flash('Enter a valid service price (0 to 1 000 000).', 'err');
                } else {
                    q("REPLACE INTO site_settings (k, v) VALUES ('service_price', ?), ('service_currency', ?)", [rtrim(rtrim(number_format((float)$price, 2, '.', ''), '0'), '.'), $cur]);
                    flash('Settings saved.');
                }
            }
            break;
        case 'taxes':
            $err = tax_save_from_post($_POST);
            flash($err ?? 'Tax settings saved. Unpaid invoices were recalculated.', $err === null ? 'ok' : 'err');
            break;
        case 'pages':
            foreach ((array)($_POST['pages'] ?? []) as $slug => $p) {
                q('UPDATE static_pages SET title = ?, body = ?, title_ru = ?, body_ru = ? WHERE slug = ?', [mb_substr(trim((string)($p['title'] ?? '')), 0, 190), (string)($p['body'] ?? ''), mb_substr(trim((string)($p['title_ru'] ?? '')), 0, 190) ?: null, ((string)($p['body_ru'] ?? '')) !== '' ? (string)$p['body_ru'] : null, (string)$slug]);
            }
            flash('Pages saved.');
            break;
    }
    redirect($self);
}

render('Admin', function () use ($tab) {
    $tabs = ['users' => 'Users', 'contracts' => 'Contracts', 'home' => 'Homepage', 'pages' => 'Pages', 'categories' => 'Categories', 'profile' => 'User Profile Editor', 'invoices' => 'Invoice Editor', 'taxes' => 'Taxes', 'settings' => 'Settings']; ?>
<div class="wrap">
  <h1>Admin mode</h1>
  <div class="tabs"><?php foreach ($tabs as $k => $l): ?><a class="<?= $k === $tab ? 'on' : '' ?>" href="/admin/<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>

  <?php if ($tab === 'users'): $users = rows('SELECT * FROM users ORDER BY id DESC LIMIT 500'); ?>
    <table class="admin-table">
      <tr><th>ID</th><th>Email</th><th>Name</th><th>Role</th><th>Registered</th><th></th></tr>
      <?php foreach ($users as $x): ?>
        <tr>
          <td><?= (int)$x['id'] ?></td><td><?= h($x['email']) ?></td><td><a href="/user/<?= (int)$x['id'] ?>"><?= h(display_name($x)) ?></a></td><td><?= h($x['role']) ?></td><td><?= h(fdate($x['created_at'])) ?></td>
          <td><?php if ($x['role'] !== 'admin'): ?>
            <form method="post" action="/admin/users" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="do" value="<?= $x['blocked'] ? 'unblock' : 'block' ?>"><button class="btn small <?= $x['blocked'] ? '' : 'red-outline' ?>" type="submit"><?= $x['blocked'] ? 'Unblock' : 'Block' ?></button></form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php elseif ($tab === 'contracts'): $cs = rows('SELECT c.id, c.title, c.status, c.created_at, u.email FROM contracts c JOIN users u ON u.id = c.user_id ORDER BY c.id DESC LIMIT 500'); ?>
    <table class="admin-table">
      <tr><th>ID</th><th>Title</th><th>Author</th><th>Status</th><th>Created</th><th></th></tr>
      <?php foreach ($cs as $x): ?>
        <tr>
          <td><?= (int)$x['id'] ?></td><td><a href="/contract/<?= (int)$x['id'] ?>"><?= h($x['title']) ?></a></td><td><?= h($x['email']) ?></td><td><?= h(status_label($x['status'])[0]) ?></td><td><?= h(fdate($x['created_at'])) ?></td>
          <td>
            <?php if ($x['status'] === 'published'): ?><form method="post" action="/admin/contracts" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="do" value="unpublish"><button class="btn small" type="submit">Unpublish</button></form><?php endif; ?>
            <?php if (!in_array($x['status'], ['active', 'paused'], true)): ?><form method="post" action="/contract/<?= (int)$x['id'] ?>/delete" class="inline" onsubmit="return confirm('Delete contract?')"><?= csrf_field() ?><button class="btn small red-outline" type="submit">Delete</button></form><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php elseif ($tab === 'home'): $blocks = rows('SELECT * FROM home_blocks ORDER BY position, id'); ?>
    <form method="post" action="/admin/home" class="card-form wide">
      <?= csrf_field() ?>
      <label>Main title <input name="home_title" value="<?= h(setting('home_title')) ?>" maxlength="190"></label>
      <?php foreach ($blocks as $b): ?>
        <div class="card">
          <div class="row gap">
            <label class="tiny">Order <input class="short" type="number" name="blocks[<?= (int)$b['id'] ?>][position]" value="<?= (int)$b['position'] ?>"></label>
            <label class="tiny">Color <select name="blocks[<?= (int)$b['id'] ?>][color]"><option value="green" <?= $b['color'] === 'green' ? 'selected' : '' ?>>Green</option><option value="blue" <?= $b['color'] === 'blue' ? 'selected' : '' ?>>Blue</option></select></label>
            <button class="btn small red-outline" type="submit" name="do" value="del_<?= (int)$b['id'] ?>" formnovalidate onclick="return confirm('Delete block?')">Delete</button>
          </div>
          <label class="tiny">Title <input name="blocks[<?= (int)$b['id'] ?>][title]" value="<?= h($b['title']) ?>"></label>
          <label class="tiny">Text <textarea name="blocks[<?= (int)$b['id'] ?>][body]" rows="3"><?= h($b['body']) ?></textarea></label>
        </div>
      <?php endforeach; ?>
      <div class="row between"><button class="btn green" type="submit" name="do" value="save">Save</button><button class="btn" type="submit" name="do" value="add_block">Add Block</button></div>
    </form>
  <?php elseif ($tab === 'profile'): $struct = profile_structure(); ?>
    <p class="small muted">Sections and fields defined here appear on every user's "Edit Profile" page. Users fill them in and choose which ones are public.</p>
    <form method="post" action="/admin/profile" class="card-form wide pe-form">
      <?= csrf_field() ?>
      <?php foreach ($struct as $s): ?>
        <details class="card pe-section" data-acc data-sid="<?= (int)$s['id'] ?>">
          <summary class="pe-sum"><b><?= h($s['title']) ?></b> <span class="muted tiny">ID #<?= (int)$s['id'] ?> · <?= h(party_label($s['type'] ?? null)) ?> · <?= count($s['fields']) ?> fields</span></summary>
          <div class="row gap end">
            <label class="tiny grow">Section title <input name="sections[<?= (int)$s['id'] ?>][title]" value="<?= h($s['title']) ?>" maxlength="120"></label>
            <label class="tiny">Order <input class="short" type="number" name="sections[<?= (int)$s['id'] ?>][position]" value="<?= (int)$s['position'] ?>"></label>
            <button class="btn small red-outline" type="submit" name="do" value="del_section_<?= (int)$s['id'] ?>" formnovalidate onclick="return confirm('Delete this section with all its fields and user data?')">Delete section</button>
          </div>
          <div class="row gap small pe-types"><b>Section type</b>
            <?php foreach (PARTY_TYPES as $tk => $tl): ?><label class="check"><input type="radio" name="sections[<?= (int)$s['id'] ?>][type]" value="<?= h($tk) ?>" <?= ($s['type'] ?? 'personal') === $tk ? 'checked' : '' ?>> <?= h($tl) ?></label><?php endforeach; ?>
            <span class="muted tiny">Section ID #<?= (int)$s['id'] ?></span>
          </div>
          <?php foreach ($s['fields'] as $f): ?>
            <div class="pe-field">
              <div class="row gap end">
                <span class="field-id" title="Field ID used in invoice templates">ID #<?= (int)$f['id'] ?></span>
                <label class="tiny grow">Field name <input name="fields[<?= (int)$f['id'] ?>][label]" value="<?= h($f['label']) ?>" maxlength="120"></label>
                <label class="tiny">Type <select name="fields[<?= (int)$f['id'] ?>][type]"><?php foreach (PROFILE_FIELD_TYPES as $k => $lbl): ?><option value="<?= h($k) ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?></select></label>
                <label class="tiny">Order <input class="short" type="number" name="fields[<?= (int)$f['id'] ?>][position]" value="<?= (int)$f['position'] ?>"></label>
              </div>
              <div class="row gap end">
                <label class="check tiny"><input type="checkbox" name="fields[<?= (int)$f['id'] ?>][required]" value="1" <?= $f['required'] ? 'checked' : '' ?>> Required</label>
                <label class="check tiny"><input type="checkbox" name="fields[<?= (int)$f['id'] ?>][public]" value="1" <?= $f['default_public'] ? 'checked' : '' ?>> Public by default</label>
                <button class="linkbtn red tiny" type="submit" name="do" value="del_field_<?= (int)$f['id'] ?>" formnovalidate onclick="return confirm('Delete this field and the values users entered?')">Delete field</button>
              </div>
              <?php if ($f['type'] === 'select'): ?><label class="tiny">Choices (one per line) <textarea name="fields[<?= (int)$f['id'] ?>][options]" rows="3"><?= h($f['options']) ?></textarea></label><?php endif; ?>
            </div>
          <?php endforeach; ?>
          <div class="pe-field pe-new">
            <div class="tiny muted">Add a field to this section</div>
            <div class="row gap end">
              <label class="tiny grow">Field name <input name="nf[<?= (int)$s['id'] ?>][label]" maxlength="120"></label>
              <label class="tiny">Type <select name="nf[<?= (int)$s['id'] ?>][type]"><?php foreach (PROFILE_FIELD_TYPES as $k => $lbl): ?><option value="<?= h($k) ?>"><?= h($lbl) ?></option><?php endforeach; ?></select></label>
              <label class="check tiny"><input type="checkbox" name="nf[<?= (int)$s['id'] ?>][required]" value="1"> Required</label>
              <label class="check tiny"><input type="checkbox" name="nf[<?= (int)$s['id'] ?>][public]" value="1" checked> Public</label>
              <button class="btn small green-outline" type="submit" name="do" value="add_field">+ Add Field</button>
            </div>
            <label class="tiny">Choices for a "Choice list" (one per line) <textarea name="nf[<?= (int)$s['id'] ?>][options]" rows="2"></textarea></label>
          </div>
        </details>
      <?php endforeach; ?>
      <?php if (!$struct): ?><p class="muted">No sections yet. Add the first one below.</p><?php endif; ?>
      <div class="row between"><button class="btn green" type="submit" name="do" value="save">Save</button></div>
    </form>
    <form method="post" action="/admin/profile" class="card row gap end"><?= csrf_field() ?>
      <label class="tiny grow">New section <input name="new_section" maxlength="120" required></label>
      <div class="row gap small pe-types"><b>Type</b>
        <?php foreach (PARTY_TYPES as $tk => $tl): ?><label class="check"><input type="radio" name="new_type" value="<?= h($tk) ?>" <?= $tk === 'personal' ? 'checked' : '' ?>> <?= h($tl) ?></label><?php endforeach; ?>
      </div>
      <button class="btn green" type="submit" name="do" value="add_section">Add Section</button>
    </form>
  <?php elseif ($tab === 'invoices'): admin_invoices_view(); ?>
  <?php elseif ($tab === 'taxes'): $tx = tax_config(true); $rules = $tx['item_level_vat_override']; while (count($rules) < 3) { $rules[] = ['match' => '', 'rate' => '']; }
      $open = (int)val("SELECT COUNT(*) FROM invoices WHERE status IN ('waiting','pending')"); ?>
    <form method="post" action="/admin/taxes" class="card-form wide">
      <?= csrf_field() ?>
      <h3>Taxes on invoices</h3>
      <p class="small muted">The To Do price is the base of an invoice. Net amount, VAT and total are calculated from it with these settings every time an invoice is shown, mailed or put in the PDF, so a change here recalculates all unpaid invoices (<b><?= $open ?></b> now). A paid invoice keeps the numbers it had when the payment was confirmed.</p>
      <div class="field-row">
        <label>Tax mode
          <select name="tax_mode"><?php foreach (TAX_MODES as $k => $l): ?><option value="<?= h($k) ?>" <?= $tx['tax_mode'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
        <label>Default VAT rate, %
          <input name="default_vat_rate" value="<?= h(tax_num($tx['default_vat_rate'])) ?>" inputmode="decimal" list="vat-rates"></label>
        <datalist id="vat-rates"><option value="20"><option value="10"><option value="0"></datalist>
      </div>
      <label class="check"><input type="checkbox" name="vat_included_by_default" value="1" <?= $tx['vat_included_by_default'] ? 'checked' : '' ?>> The To Do price already includes VAT (otherwise VAT is added on top)</label>
      <p class="small muted">OSN: VAT is charged. USN 6% / USN 15%: no VAT, the legal basis below is printed on the invoice. Other: if the exemption rule is filled the invoice is "Without VAT" with that reference, otherwise VAT is charged like in OSN.</p>

      <h3>Item VAT rates</h3>
      <p class="small muted">Optional overrides: if the task name or the contract category contains the text, this rate is used instead of the default. The first matching row wins. Used only when VAT is charged.</p>
      <?php foreach ($rules as $i => $r): ?>
        <div class="field-row">
          <label>Text in task / category <input name="override[<?= $i ?>][match]" value="<?= h($r['match']) ?>" maxlength="120"></label>
          <label>VAT rate, % <input name="override[<?= $i ?>][rate]" value="<?= h(tax_num($r['rate'])) ?>" inputmode="decimal" list="vat-rates"></label>
        </div>
      <?php endforeach; ?>
      <p class="small muted">Empty rows are ignored. Save to get three more empty rows.</p>

      <h3>"Without VAT" legal basis</h3>
      <label>USN description (English) <textarea name="usn_description" rows="2"><?= h($tx['usn_description']) ?></textarea></label>
      <label>USN description (Russian) <textarea name="usn_description_ru" rows="2"><?= h($tx['usn_description_ru']) ?></textarea></label>
      <label>Exemption rule, e.g. "Article 149 of the Tax Code" <textarea name="exemption_rule" rows="2"><?= h($tx['exemption_rule']) ?></textarea></label>
      <label>Exemption rule (Russian) <textarea name="exemption_rule_ru" rows="2"><?= h($tx['exemption_rule_ru']) ?></textarea></label>
      <p class="small muted">In the USN text you can use <b>{article}</b> (346.11) and <b>{mode}</b> (USN 6% / USN 15%).</p>

      <h3>Rounding and format</h3>
      <div class="field-row">
        <label>Rounding
          <select name="rounding_mode"><?php foreach (TAX_ROUNDING as $k => $l): ?><option value="<?= h($k) ?>" <?= $tx['rounding_mode'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
        <label>Default currency <input name="currency" value="<?= h($tx['currency']) ?>" maxlength="3" size="4"></label>
      </div>
      <div class="field-row">
        <label>Decimal separator <input name="decimal" value="<?= h($tx['locale_settings']['decimal']) ?>" maxlength="2" size="3"></label>
        <label>Thousands separator <input name="thousands" value="<?= h($tx['locale_settings']['thousands']) ?>" maxlength="2" size="3"></label>
        <label>Currency symbol
          <select name="symbol_position"><option value="after" <?= $tx['locale_settings']['symbol_position'] === 'after' ? 'selected' : '' ?>>after the number</option><option value="before" <?= $tx['locale_settings']['symbol_position'] === 'before' ? 'selected' : '' ?>>before the number</option></select></label>
      </div>
      <div class="row between"><button class="btn green" type="submit">Save and recalculate</button></div>
    </form>
    <?php $smp = tax_calc(1000.0, tax_item_rate($tx, '', ''), $tx); ?>
    <div class="card-form wide">
      <h3>Example with the saved settings: a To Do price of 1000</h3>
      <table class="admin-table">
        <tr><td>Net amount</td><td class="right"><?= h(tax_money($smp['net'])) ?></td></tr>
        <tr><td>VAT<?= $smp['no_vat'] ? '' : ' ' . h(tax_num($smp['rate'])) . '%' ?></td><td class="right"><?= $smp['no_vat'] ? 'without VAT' : h(tax_money($smp['vat'])) ?></td></tr>
        <tr><td><b>Total</b></td><td class="right"><b><?= h(tax_money($smp['total'])) ?></b></td></tr>
      </table>
      <?php if ($smp['note_en'] !== ''): ?><p class="small"><b><?= h($smp['note_en']) ?></b></p><?php endif; ?>
    </div>
  <?php elseif ($tab === 'settings'): [$spAmount, $spCur] = service_price(); ?>
    <form method="post" action="/admin/settings" class="card-form wide">
      <?= csrf_field() ?>
      <h3>Service price</h3>
      <p class="small muted">The price of publishing a contract. It is shown on the Prices page (use <b>{price}</b> in the page text) and on the registration form.</p>
      <div class="field-row">
        <label>Price <input name="service_price" value="<?= h(rtrim(rtrim(number_format($spAmount, 2, '.', ''), '0'), '.')) ?>" inputmode="decimal" required></label>
        <label>Currency <select name="service_currency"><?php foreach (CURRENCIES as $code => $cur): ?><option value="<?= h($code) ?>" <?= $spCur === $code ? 'selected' : '' ?>><?= h($cur[1]) ?></option><?php endforeach; ?></select></label>
      </div>
      <p class="small">Shown as: <b><?= h(service_price_text('en')) ?></b> / <b><?= h(service_price_text('ru')) ?></b></p>
      <div class="row between"><button class="btn green" type="submit" name="do" value="save">Save</button></div>
    </form>
    <form method="post" action="/admin/settings" class="card-form wide">
      <?= csrf_field() ?>
      <h3>E-mail notifications</h3>
      <p class="small muted">Authors get an e-mail (and a notice on the site) when a contract without a Contractor is archived after one month. The mail server is set by the SMTP secrets of the repository.</p>
      <button class="btn" type="submit" name="do" value="test_mail">Send a test e-mail to me</button>
      <a class="btn" href="/admin/settings?preview=en" target="_blank">Preview (EN)</a>
      <a class="btn" href="/admin/settings?preview=ru" target="_blank">Preview (RU)</a>
    </form>
  <?php elseif ($tab === 'categories'): $cats = rows('SELECT c.*, (SELECT COUNT(*) FROM contracts x WHERE x.category_id = c.id) AS n FROM categories c ORDER BY c.name'); ?>
    <p class="small muted">The default image is shown on contracts of this category that have no uploaded images.</p>
    <?php foreach ($cats as $cat): ?>
      <form method="post" action="/admin/categories" enctype="multipart/form-data" class="card cat-row">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
        <div class="cat-previews">
          <div class="cat-prev"><div class="cat-img cat-img-desktop"><?php if ($cat['image']): ?><img src="/uploads/<?= h($cat['image']) ?>" alt=""><?php else: ?><span>no image</span><?php endif; ?></div><div class="tiny muted">Desktop</div></div>
          <div class="cat-prev"><div class="cat-img cat-img-mobile"><?php if ($cat['image_mobile']): ?><img src="/uploads/<?= h($cat['image_mobile']) ?>" alt=""><?php else: ?><span>no image</span><?php endif; ?></div><div class="tiny muted">Mobile</div></div>
        </div>
        <div class="cat-fields">
          <label class="tiny">Name <input name="name" value="<?= h($cat['name']) ?>" maxlength="80" required></label>
          <label class="tiny">Name in Russian <span class="muted">(shown when the site is in Russian)</span> <input name="name_ru" value="<?= h($cat['name_ru'] ?? '') ?>" maxlength="80"></label>
          <label class="tiny">Desktop image <span class="muted">(shown left of the card, a tall frame of about 180 × 230-280 px; best 360 × 520 px)</span> <input type="file" name="image" accept="image/*"></label>
          <label class="tiny">Mobile image <span class="muted">(shown above the card; best 750 × 380 px; the desktop image is used if empty)</span> <input type="file" name="image_mobile" accept="image/*"></label>
          <label class="tiny">Image fit <select name="image_fit"><option value="cover" <?= ($cat['image_fit'] ?? 'cover') === 'cover' ? 'selected' : '' ?>>Fill the frame (edges may be cropped)</option><option value="contain" <?= ($cat['image_fit'] ?? '') === 'contain' ? 'selected' : '' ?>>Show the whole image</option></select></label>
          <div class="row gap">
            <button class="btn small green" type="submit" name="do" value="save">Save</button>
            <?php if ($cat['image']): ?><button class="btn small" type="submit" name="do" value="remove_image" formnovalidate>Remove desktop image</button><?php endif; ?>
            <?php if ($cat['image_mobile']): ?><button class="btn small" type="submit" name="do" value="remove_image_mobile" formnovalidate>Remove mobile image</button><?php endif; ?>
            <button class="btn small red-outline" type="submit" name="do" value="delete" formnovalidate onclick="return confirm('Delete this category?')">Delete</button>
            <span class="tiny muted"><?= (int)$cat['n'] ?> contracts</span>
          </div>
        </div>
      </form>
    <?php endforeach; ?>
    <form method="post" action="/admin/categories" class="card row gap end"><?= csrf_field() ?>
      <label class="tiny grow">New category <input name="name" maxlength="80" required></label>
      <label class="tiny grow">Name in Russian <input name="name_ru" maxlength="80"></label>
      <button class="btn green" type="submit" name="do" value="add">Add Category</button>
    </form>
  <?php else: $pgs = rows('SELECT * FROM static_pages ORDER BY slug'); ?>
    <form method="post" action="/admin/pages" class="card-form wide">
      <?= csrf_field() ?>
      <?php foreach ($pgs as $p): ?>
        <div class="card"><label class="tiny">Title (<?= h($p['slug']) ?>) <input name="pages[<?= h($p['slug']) ?>][title]" value="<?= h($p['title']) ?>"></label>
          <label class="tiny">Text <textarea name="pages[<?= h($p['slug']) ?>][body]" rows="6"><?= h($p['body']) ?></textarea></label>
          <label class="tiny">Title in Russian <input name="pages[<?= h($p['slug']) ?>][title_ru]" value="<?= h($p['title_ru'] ?? '') ?>"></label>
          <label class="tiny">Text in Russian <textarea name="pages[<?= h($p['slug']) ?>][body_ru]" rows="6"><?= h($p['body_ru'] ?? '') ?></textarea></label></div>
      <?php endforeach; ?>
      <button class="btn green" type="submit">Save</button>
    </form>
  <?php endif; ?>
</div>
<?php });
