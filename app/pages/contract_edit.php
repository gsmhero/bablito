<?php
declare(strict_types=1);

$u = require_login();
$id = isset($args[0]) ? (int)$args[0] : 0;
$c = null;
if ($id) {
    $c = load_contract($id);
    if ((int)$c['user_id'] !== (int)$u['id'] && !is_admin()) {
        forbidden();
    }
    if (!in_array($c['status'], ['draft', 'published'], true)) {
        flash('An accepted contract can no longer be edited.', 'err');
        redirect('/contract/' . $id);
    }
}
$hasOffers = $c ? (int)val('SELECT COUNT(*) FROM offers WHERE contract_id = ?', [$id]) > 0 : false;
$errors = [];

$form = [
    'title' => $c['title'] ?? '',
    'category_id' => (int)($c['category_id'] ?? 0),
    'location' => $c['location'] ?? '',
    'description' => $c['description'] ?? '',
    'allow_extra_todos' => (int)($c['allow_extra_todos'] ?? 0),
    'prepay_required' => (int)($c['prepay_required'] ?? 0),
    'deadline' => $c['deadline'] ?? '',
    'publish' => ($c['status'] ?? 'draft') === 'published' ? 1 : 0,
];
$todos = $id ? rows('SELECT * FROM todos WHERE contract_id = ? ORDER BY position, id', [$id]) : [];
if (!$todos) {
    $todos = [['id' => 0, 'name' => '', 'description' => '', 'price' => '', 'days' => 1]];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['title'] = trim((string)($_POST['title'] ?? ''));
    $form['category_id'] = (int)($_POST['category_id'] ?? 0);
    $form['location'] = trim((string)($_POST['location'] ?? ''));
    $form['description'] = trim((string)($_POST['description'] ?? ''));
    $form['allow_extra_todos'] = empty($_POST['allow_extra_todos']) ? 0 : 1;
    $form['prepay_required'] = empty($_POST['prepay_required']) ? 0 : 1;
    $form['deadline'] = trim((string)($_POST['deadline'] ?? ''));
    $form['publish'] = empty($_POST['publish']) ? 0 : 1;

    if ($form['title'] === '' || mb_strlen($form['title']) > 190) {
        $errors[] = 'Contract name is required (up to 190 characters).';
    }
    if (mb_strlen($form['location']) > 190) {
        $errors[] = 'Location is too long.';
    }
    if ($form['category_id'] && !val('SELECT id FROM categories WHERE id = ?', [$form['category_id']])) {
        $form['category_id'] = 0;
    }
    if ($form['deadline'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['deadline'])) {
        $errors[] = 'Till date is invalid.';
    }

    $newTodos = [];
    if (!$hasOffers) {
        foreach ((array)($_POST['todos'] ?? []) as $t) {
            $name = trim((string)($t['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $price = (float)str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string)($t['price'] ?? '0'));
            $days = (int)($t['days'] ?? 1);
            if ($price < 0 || $price > 999999999 || $days < 0 || $days > 3650) {
                $errors[] = 'Check price and days of "' . $name . '".';
            }
            $newTodos[] = ['id' => (int)($t['id'] ?? 0), 'name' => mb_substr($name, 0, 190), 'description' => trim((string)($t['description'] ?? '')), 'price' => $price, 'days' => max(0, $days)];
        }
        if (!$newTodos) {
            $errors[] = 'Add at least one To Do.';
        } else {
            $todos = $newTodos;
        }
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $status = $form['publish'] ? 'published' : 'draft';
            if ($form['deadline'] === '' && !$hasOffers) {
                $form['deadline'] = date('Y-m-d', strtotime('+' . array_sum(array_column($todos, 'days')) . ' days'));
            }
            $vals = [$form['title'], $form['category_id'] ?: null, $form['location'], $form['description'], $form['allow_extra_todos'], $form['prepay_required'], $form['deadline'] ?: null, $status];
            if ($id) {
                q('UPDATE contracts SET title=?, category_id=?, location=?, description=?, allow_extra_todos=?, prepay_required=?, deadline=?, status=? WHERE id=?', [...$vals, $id]);
            } else {
                q('INSERT INTO contracts (title, category_id, location, description, allow_extra_todos, prepay_required, deadline, status, user_id) VALUES (?,?,?,?,?,?,?,?,?)', [...$vals, $u['id']]);
                $id = (int)$pdo->lastInsertId();
            }
            if (!$hasOffers) {
                $keep = [];
                foreach ($todos as $pos => $t) {
                    if ($t['id'] && val('SELECT id FROM todos WHERE id = ? AND contract_id = ?', [$t['id'], $id])) {
                        q('UPDATE todos SET position=?, name=?, description=?, price=?, days=? WHERE id=?', [$pos, $t['name'], $t['description'], $t['price'], $t['days'], $t['id']]);
                        $keep[] = (int)$t['id'];
                    } else {
                        q('INSERT INTO todos (contract_id, position, name, description, price, days) VALUES (?,?,?,?,?,?)', [$id, $pos, $t['name'], $t['description'], $t['price'], $t['days']]);
                        $keep[] = (int)$pdo->lastInsertId();
                    }
                }
                q('DELETE FROM todos WHERE contract_id = ? AND id NOT IN (' . implode(',', $keep) . ')', [$id]);
            }

            foreach ((array)($_POST['delete_file'] ?? []) as $fid) {
                $f = row('SELECT * FROM files WHERE id = ? AND contract_id = ?', [(int)$fid, $id]);
                if ($f) {
                    @unlink(ROOT . '/uploads/' . $f['stored_name']);
                    q('DELETE FROM files WHERE id = ?', [$f['id']]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $warn = [];
        foreach (['images' => 'image', 'files' => 'file'] as $field => $kind) {
            if (empty($_FILES[$field]['name'][0] ?? '')) {
                continue;
            }
            foreach ($_FILES[$field]['name'] as $i => $_) {
                $one = ['name' => $_FILES[$field]['name'][$i], 'tmp_name' => $_FILES[$field]['tmp_name'][$i], 'size' => $_FILES[$field]['size'][$i], 'error' => $_FILES[$field]['error'][$i]];
                if ($one['error'] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($kind === 'image' && (int)val("SELECT COUNT(*) FROM files WHERE contract_id = ? AND kind = 'image'", [$id]) >= 8) {
                    $warn[] = 'Up to 8 images per contract.';
                    break;
                }
                $res = store_upload($one, $kind);
                if (is_string($res)) {
                    $warn[] = $one['name'] . ': ' . $res;
                    continue;
                }
                q('INSERT INTO files (contract_id, user_id, kind, stored_name, original_name) VALUES (?,?,?,?,?)', [$id, $u['id'], $res[0], $res[1], $res[2]]);
            }
        }
        flash($warn ? 'Saved. ' . implode(' ', array_unique($warn)) : 'Contract saved.', $warn ? 'err' : 'ok');
        redirect('/contract/' . $id);
    }
}

$files = $id ? rows('SELECT * FROM files WHERE contract_id = ? AND by_contractor = 0 ORDER BY id', [$id]) : [];

render($id ? 'Edit contract' : 'New contract', function () use ($id, $c, $form, $todos, $files, $errors, $hasOffers) {
    $sumP = 0;
    $sumD = 0;
    foreach ($todos as $t) {
        $sumP += (float)$t['price'];
        $sumD += (int)$t['days'];
    } ?>
<form method="post" enctype="multipart/form-data" class="wrap two-col" id="contract-form">
  <?= csrf_field() ?>
  <div class="col">
    <?php foreach ($errors as $e): ?><div class="flash err"><?= h($e) ?></div><?php endforeach; ?>
    <input class="title-input" name="title" value="<?= h($form['title']) ?>" placeholder="Contract name" maxlength="190" required>
    <div class="row between">
      <span class="muted"><?= $c ? h(status_label($c['status'])[0]) : 'Status: new' ?></span>
      <label class="inline-label">Category
        <select name="category_id">
          <option value="0">Select</option>
          <?php foreach (categories() as $cat): ?><option value="<?= (int)$cat['id'] ?>" <?= $form['category_id'] === (int)$cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
    </div>
    <label class="small">Location <input name="location" value="<?= h($form['location']) ?>" maxlength="190"></label>
    <label class="small">Description <textarea name="description" rows="6"><?= h($form['description']) ?></textarea></label>

    <div class="row between"><h3>Contract</h3>
      <label class="check"><input type="checkbox" name="allow_extra_todos" value="1" <?= $form['allow_extra_todos'] ? 'checked' : '' ?>> Make Me Offer</label>
    </div>
    <?php if ($hasOffers): ?><p class="muted small">Offers already exist, so the steps can no longer be changed.</p><?php endif; ?>
    <div id="todos">
      <?php foreach ($todos as $i => $t): ?>
        <div class="todo-edit card">
          <input type="hidden" name="todos[<?= $i ?>][id]" value="<?= (int)$t['id'] ?>">
          <label class="tiny">To Do Name <input name="todos[<?= $i ?>][name]" value="<?= h($t['name']) ?>" maxlength="190" <?= $hasOffers ? 'readonly' : '' ?>></label>
          <label class="tiny">Description <textarea name="todos[<?= $i ?>][description]" rows="2" <?= $hasOffers ? 'readonly' : '' ?>><?= h($t['description']) ?></textarea></label>
          <div class="row gap">
            <label class="tiny">Price <input class="t-price" name="todos[<?= $i ?>][price]" value="<?= h($t['price'] === '' ? '' : (string)(float)$t['price']) ?>" inputmode="decimal" <?= $hasOffers ? 'readonly' : '' ?>></label>
            <label class="tiny">Days <input class="t-days" name="todos[<?= $i ?>][days]" type="number" min="0" value="<?= (int)$t['days'] ?>" <?= $hasOffers ? 'readonly' : '' ?>></label>
            <?php if (!$hasOffers): ?><button type="button" class="linkbtn red remove-todo">Remove</button><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (!$hasOffers): ?><div class="right"><button type="button" class="btn small green-outline" id="add-todo">+ Add To Do</button></div><?php endif; ?>
  </div>

  <div class="col">
    <hr>
    <label class="btn small file-btn">Upload Image <input type="file" name="images[]" accept="image/*" multiple hidden></label>
    <span class="muted small" id="img-count"></span>
    <div class="gallery">
      <?php foreach ($files as $f): if ($f['kind'] !== 'image') { continue; } ?>
        <figure><img src="/uploads/<?= h($f['stored_name']) ?>" alt=""><label class="tiny"><input type="checkbox" name="delete_file[]" value="<?= (int)$f['id'] ?>"> remove</label></figure>
      <?php endforeach; ?>
    </div>
    <hr>
    <h3>Files</h3>
    <label class="btn small file-btn">Upload File <input type="file" name="files[]" multiple hidden></label>
    <ul class="files">
      <?php foreach ($files as $f): if ($f['kind'] !== 'file') { continue; } ?>
        <li><a href="/uploads/<?= h($f['stored_name']) ?>" download="<?= h($f['original_name']) ?>"><?= h($f['original_name']) ?></a> <label class="tiny"><input type="checkbox" name="delete_file[]" value="<?= (int)$f['id'] ?>"> remove</label></li>
      <?php endforeach; ?>
    </ul>
    <hr>
    <div class="sums">
      <div><div class="lbl">Price</div><div class="big green" id="sum-price"><?= money($sumP) ?></div><div class="cur">RUB</div></div>
      <div class="center"><div class="lbl">Time days</div><div class="big green" id="sum-days"><?= $sumD ?></div></div>
      <div class="center"><div class="lbl">Till Date</div><input type="date" name="deadline" value="<?= h($form['deadline']) ?>"></div>
    </div>
    <hr>
    <div class="row between">
      <button class="btn green" type="submit">Save Contract</button>
      <label class="check big-check"><input type="checkbox" name="publish" value="1" <?= $form['publish'] ? 'checked' : '' ?>> Publish Contract</label>
    </div>
    <div class="stack-right">
      <label class="btn blue-outline"><input type="checkbox" name="prepay_required" value="1" <?= $form['prepay_required'] ? 'checked' : '' ?>> Prepay Contract</label>
      <a class="btn" href="<?= $id ? '/contract/' . $id . '/edit' : '/contract/new' ?>">Reset Contract</a>
      <?php if ($id): ?><button class="btn red-outline" type="submit" formaction="/contract/<?= $id ?>/delete" formnovalidate onclick="return confirm('Delete this contract?')">Delete Contract</button><?php endif; ?>
    </div>
  </div>
</form>
<template id="todo-tpl">
  <div class="todo-edit card">
    <input type="hidden" name="todos[__i__][id]" value="0">
    <label class="tiny">To Do Name <input name="todos[__i__][name]" maxlength="190"></label>
    <label class="tiny">Description <textarea name="todos[__i__][description]" rows="2"></textarea></label>
    <div class="row gap">
      <label class="tiny">Price <input class="t-price" name="todos[__i__][price]" inputmode="decimal"></label>
      <label class="tiny">Days <input class="t-days" name="todos[__i__][days]" type="number" min="0" value="1"></label>
      <button type="button" class="linkbtn red remove-todo">Remove</button>
    </div>
  </div>
</template>
<?php });
