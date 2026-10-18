<?php
declare(strict_types=1);

require_once ROOT . '/app/card.php';
$me = require_admin();
$tab = $args[0] ?? 'users';
$self = '/admin/' . $tab;

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
        case 'pages':
            foreach ((array)($_POST['pages'] ?? []) as $slug => $p) {
                q('UPDATE static_pages SET title = ?, body = ? WHERE slug = ?', [mb_substr(trim((string)($p['title'] ?? '')), 0, 190), (string)($p['body'] ?? ''), (string)$slug]);
            }
            flash('Pages saved.');
            break;
    }
    redirect($self);
}

render('Admin', function () use ($tab) {
    $tabs = ['users' => 'Users', 'contracts' => 'Contracts', 'home' => 'Homepage', 'pages' => 'Pages']; ?>
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
  <?php else: $pgs = rows('SELECT * FROM static_pages ORDER BY slug'); ?>
    <form method="post" action="/admin/pages" class="card-form wide">
      <?= csrf_field() ?>
      <?php foreach ($pgs as $p): ?>
        <div class="card"><label class="tiny">Title (<?= h($p['slug']) ?>) <input name="pages[<?= h($p['slug']) ?>][title]" value="<?= h($p['title']) ?>"></label>
          <label class="tiny">Text <textarea name="pages[<?= h($p['slug']) ?>][body]" rows="6"><?= h($p['body']) ?></textarea></label></div>
      <?php endforeach; ?>
      <button class="btn green" type="submit">Save</button>
    </form>
  <?php endif; ?>
</div>
<?php });
