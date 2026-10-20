<?php
declare(strict_types=1);

// Invoice Editor (admin) and the invoice view that uses the templates.
// A template is chosen by "From status -> To status": From = the contractor (issuer),
// To = the contract author (payer), using the statuses chosen on the offer / contract.

const INVOICE_SYSTEM_FIELDS = [
    'u:name' => 'User name',
    'u:email' => 'Contact email',
    'u:phone' => 'Contact phone',
    's:number' => 'Invoice number',
    's:date' => 'Invoice date',
    's:contract' => 'Contract name',
    's:todo_name' => 'Todo name',
    's:todo_no' => 'Todo №',
    's:price' => 'Price',
    's:status' => 'Status',
];

/** Allowed HTML for template header and footer: formatting tags only, no attributes. */
function sanitize_invoice_html(string $html): string
{
    $html = strip_tags($html, '<h1><h2><h3><h4><b><strong><i><em><u><br><p><hr><ul><ol><li><div><span><small>');
    return (string)preg_replace('/<(\/?)([a-z0-9]+)\b[^>]*?(\/?)>/i', '<$1$2$3>', $html);
}

function invoice_pair_label(string $from, string $to): string
{
    return party_label($from) . ' → ' . party_label($to);
}

/** Dropdown options: fields of the User Profile Editor (by ID) and invoice data. */
function invoice_field_options_html(?string $selected): string
{
    $o = '<option value="">Choose a field…</option><optgroup label="User Profile Editor fields">';
    foreach (profile_structure() as $s) {
        foreach ($s['fields'] as $f) {
            $tok = 'p' . (int)$f['id'];
            $o .= '<option value="' . h($tok) . '"' . ($tok === $selected ? ' selected' : '') . '>ID #' . (int)$f['id'] . ' — ' . h($f['label']) . ' (' . h($s['title']) . ', ' . h(party_label($s['type'] ?? 'personal')) . ')</option>';
        }
    }
    $o .= '</optgroup><optgroup label="User and invoice data">';
    foreach (INVOICE_SYSTEM_FIELDS as $tok => $lbl) {
        $o .= '<option value="' . h($tok) . '"' . ($tok === $selected ? ' selected' : '') . '>' . h($lbl) . '</option>';
    }
    return $o . '</optgroup>';
}

function invoice_field_row_html(string $name, ?string $selected): string
{
    return '<div class="inv-field-row"><select name="' . h($name) . '[]">' . invoice_field_options_html($selected) . '</select><button type="button" class="linkbtn red rm-fld">Remove</button></div>';
}

function invoice_tokens(?string $json): array
{
    $a = json_decode((string)$json, true);
    return is_array($a) ? array_values(array_filter($a, 'is_string')) : [];
}

function valid_invoice_token(string $t): bool
{
    return isset(INVOICE_SYSTEM_FIELDS[$t]) || (bool)preg_match('/^p\d+$/', $t);
}

/* ---------- admin: POST and view ---------- */

function admin_invoices_post(string $do, string $self): void
{
    if ($do === 'add') {
        q("INSERT INTO invoice_templates (name, from_type, to_type, header_html, footer_html, from_fields, to_fields, content_fields) VALUES ('New invoice template', 'personal', 'personal', '', '', '[]', '[]', '[]')");
        flash('Empty template created.');
        redirect($self . '?edit=' . (int)db()->lastInsertId());
    }
    if (str_starts_with($do, 'clone_')) {
        $src = row('SELECT * FROM invoice_templates WHERE id = ?', [(int)substr($do, 6)]);
        if ($src) {
            q('INSERT INTO invoice_templates (name, from_type, to_type, header_html, footer_html, from_fields, to_fields, content_fields) VALUES (?,?,?,?,?,?,?,?)',
                [mb_substr($src['name'], 0, 108) . ' (copy)', $src['from_type'], $src['to_type'], $src['header_html'], $src['footer_html'], $src['from_fields'], $src['to_fields'], $src['content_fields']]);
            flash('Template cloned. Change its type or fields and save.');
            redirect($self . '?edit=' . (int)db()->lastInsertId());
        }
        redirect($self);
    }
    if (str_starts_with($do, 'delete_')) {
        q('DELETE FROM invoice_templates WHERE id = ?', [(int)substr($do, 7)]);
        flash('Template deleted.');
        redirect($self);
    }
    if ($do === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        if (!val('SELECT id FROM invoice_templates WHERE id = ?', [$id])) {
            redirect($self);
        }
        $pair = explode('>', (string)($_POST['pair'] ?? 'personal>personal'));
        $from = isset(PARTY_TYPES[$pair[0] ?? '']) ? $pair[0] : 'personal';
        $to = isset(PARTY_TYPES[$pair[1] ?? '']) ? $pair[1] : 'personal';
        $lists = [];
        foreach (['from_fields', 'to_fields', 'content_fields'] as $k) {
            $lists[$k] = json_encode(array_values(array_filter((array)($_POST[$k] ?? []), fn($t) => is_string($t) && valid_invoice_token($t))));
        }
        q('UPDATE invoice_templates SET name = ?, from_type = ?, to_type = ?, header_html = ?, footer_html = ?, from_fields = ?, to_fields = ?, content_fields = ? WHERE id = ?', [
            mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120) ?: 'Invoice template', $from, $to,
            sanitize_invoice_html((string)($_POST['header_html'] ?? '')), sanitize_invoice_html((string)($_POST['footer_html'] ?? '')),
            $lists['from_fields'], $lists['to_fields'], $lists['content_fields'], $id,
        ]);
        flash('Template saved.');
        redirect($self . '?edit=' . $id);
    }
    redirect($self);
}

function admin_invoices_view(): void
{
    $editId = (int)($_GET['edit'] ?? 0);
    $t = $editId ? row('SELECT * FROM invoice_templates WHERE id = ?', [$editId]) : null;
    if (!$t) { ?>
    <p class="small muted">Templates are chosen automatically by the statuses of the contractor (From) and the contract author (To).</p>
    <form method="post" action="/admin/invoices" class="row between"><?= csrf_field() ?>
      <h3>Invoice templates</h3><button class="btn green" type="submit" name="do" value="add">Add Invoice</button>
    </form>
    <?php $list = rows('SELECT * FROM invoice_templates ORDER BY id'); ?>
    <?php if (!$list): ?><p class="muted">No templates yet.</p><?php else: ?>
    <table class="admin-table">
      <tr><th>ID</th><th>Name</th><th>Type (From → To)</th><th>Updated</th><th></th></tr>
      <?php foreach ($list as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td><td><?= h($r['name']) ?></td><td><?= h(invoice_pair_label($r['from_type'], $r['to_type'])) ?></td><td><?= h(fdate($r['updated_at'])) ?></td>
          <td>
            <a class="btn small" href="/admin/invoices?edit=<?= (int)$r['id'] ?>">Edit Invoice</a>
            <form method="post" action="/admin/invoices" class="inline"><?= csrf_field() ?><button class="btn small" type="submit" name="do" value="clone_<?= (int)$r['id'] ?>">Clone</button></form>
            <form method="post" action="/admin/invoices" class="inline" onsubmit="return confirm('Delete this template?')"><?= csrf_field() ?><button class="btn small red-outline" type="submit" name="do" value="delete_<?= (int)$r['id'] ?>">Delete Invoice</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php endif;
        return;
    }
    $pairNow = $t['from_type'] . '>' . $t['to_type'];
    $cols = ['personal', 'self', 'company'];
    $targets = ['company', 'self', 'personal'];
    $lists = ['from' => invoice_tokens($t['from_fields']), 'to' => invoice_tokens($t['to_fields']), 'content' => invoice_tokens($t['content_fields'])]; ?>
    <p><a href="/admin/invoices">&larr; Templates</a></p>
    <form method="post" action="/admin/invoices" class="card-form wide inv-editor" id="inv-editor">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
      <label>Template name <input name="name" value="<?= h($t['name']) ?>" maxlength="120"></label>

      <h3>Template type</h3>
      <div class="inv-matrix">
        <?php foreach ($cols as $from): ?>
          <div class="inv-col">
            <?php foreach ($targets as $to): $v = $from . '>' . $to; ?>
              <label class="check"><input type="radio" name="pair" value="<?= h($v) ?>" <?= $pairNow === $v ? 'checked' : '' ?>> <?= h(invoice_pair_label($from, $to)) ?></label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <h3>Header</h3>
      <textarea name="header_html" rows="4" placeholder="HTML allowed: &lt;h1&gt;, &lt;b&gt;, &lt;i&gt;, &lt;br&gt;"><?= h($t['header_html']) ?></textarea>

      <div class="inv-two">
        <?php foreach (['from' => 'From (contractor)', 'to' => 'To (contract author)'] as $k => $title): ?>
          <div class="card">
            <h3><?= h($title) ?></h3>
            <div class="inv-list" id="list-<?= $k ?>"><?php foreach ($lists[$k] as $tok) { echo invoice_field_row_html($k . '_fields', $tok); } ?></div>
            <button type="button" class="btn small green-outline add-fld" data-list="list-<?= $k ?>" data-name="<?= $k ?>_fields">+ Add new field</button>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="card">
        <h3>Content</h3>
        <div class="inv-list" id="list-content"><?php foreach ($lists['content'] as $tok) { echo invoice_field_row_html('content_fields', $tok); } ?></div>
        <button type="button" class="btn small green-outline add-fld" data-list="list-content" data-name="content_fields">+ Add new field</button>
      </div>

      <h3>Footer</h3>
      <textarea name="footer_html" rows="4" placeholder="HTML allowed: &lt;h1&gt;, &lt;b&gt;, &lt;i&gt;, &lt;br&gt;"><?= h($t['footer_html']) ?></textarea>

      <div class="row between"><button class="btn green" type="submit" name="do" value="save">Save template</button> <button class="btn" type="submit" name="do" value="clone_<?= (int)$t['id'] ?>" formnovalidate onclick="return confirm('Clone the saved version of this template?')">Clone</button></div>
    </form>
    <template id="fld-tpl"><div class="inv-field-row"><select name="__n__[]"><?= invoice_field_options_html(null) ?></select><button type="button" class="linkbtn red rm-fld">Remove</button></div></template>
<?php
}

/* ---------- invoice view ---------- */

function pick_invoice_template(string $from, string $to): ?array
{
    return row('SELECT * FROM invoice_templates WHERE from_type = ? AND to_type = ? ORDER BY updated_at DESC, id DESC LIMIT 1', [$from, $to]);
}

/** Label and text value of a template field for a given user, or null when empty. */
function invoice_field_value(string $tok, array $inv, array $user): ?array
{
    if (isset(INVOICE_SYSTEM_FIELDS[$tok])) {
        $label = INVOICE_SYSTEM_FIELDS[$tok];
        $sym = CURRENCIES[$inv['currency']][0] ?? '₽';
        $v = match ($tok) {
            'u:name' => display_name($user),
            'u:email' => (string)($user['contact_email'] ?: $user['email']),
            'u:phone' => (string)$user['contact_phone'],
            's:number' => '#' . $inv['id'],
            's:date' => fdate($inv['created_at']),
            's:contract' => (string)$inv['contract_title'],
            's:todo_name' => (string)($inv['todo_name'] ?? 'Whole contract'),
            's:todo_no' => $inv['todo_name'] !== null ? (string)((int)$inv['todo_pos'] + 1) : '-',
            's:price' => $sym . ' ' . money($inv['amount']),
            's:status' => INVOICE_STATUS[$inv['status']] ?? $inv['status'],
            default => '',
        };
        return $v === '' ? null : [$label, $v];
    }
    $fid = (int)substr($tok, 1);
    $f = row('SELECT label, type FROM profile_fields WHERE id = ?', [$fid]);
    $val = $f ? val('SELECT value FROM profile_values WHERE user_id = ? AND field_id = ?', [$user['id'], $fid]) : null;
    if (!$f || $val === null || $val === '') {
        return null;
    }
    return [(string)$f['label'], $f['type'] === 'date' ? fdate((string)$val) : (string)$val];
}
