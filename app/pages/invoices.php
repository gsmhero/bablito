<?php
declare(strict_types=1);

$u = require_login();
$tab = ($_GET['tab'] ?? 'pay') === 'receive' ? 'receive' : 'pay';
$col = $tab === 'pay' ? 'payer_id' : 'payee_id';
$sort = in_array($_GET['sort'] ?? '', ['date', 'contract'], true) ? $_GET['sort'] : 'date';
$dir = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
$search = trim((string)($_GET['q'] ?? ''));
$where = '';
$args = [$u['id']];
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_' . chr(92)) . '%';
    $where = ' AND (c.title LIKE ? OR t.name LIKE ? OR i.number LIKE ?)';
    array_push($args, $like, $like, $like);
}
$order = $sort === 'contract' ? 'c.title ' . $dir . ', i.id DESC' : 'i.created_at ' . $dir . ', i.id ' . $dir;
$qs = function (array $over) use ($tab, $sort, $dir, $search) {
    return '/invoices?' . http_build_query(array_filter(array_merge(['tab' => $tab, 'sort' => $sort, 'dir' => $dir, 'q' => $search], $over), fn($v) => $v !== ''));
};

$list = rows("SELECT i.*, c.title AS contract_title, c.user_id AS c_author, t.name AS todo_name, t.position AS todo_pos,
        (SELECT COUNT(*) FROM todos x WHERE x.contract_id = c.id) AS steps
    FROM invoices i JOIN contracts c ON c.id = i.contract_id LEFT JOIN todos t ON t.id = i.todo_id
    WHERE i.$col = ?$where ORDER BY $order LIMIT 300", $args);
$counts = [
    'pay' => (int)val("SELECT COUNT(*) FROM invoices WHERE payer_id = ? AND status = 'waiting'", [$u['id']]),
    'receive' => (int)val("SELECT COUNT(*) FROM invoices WHERE payee_id = ? AND status = 'pending'", [$u['id']]),
];

render('Invoices', function () use ($list, $tab, $counts, $u, $sort, $dir, $search, $qs) { ?>
<div class="wrap">
  <h1>Invoices</h1>
  <div class="tabs">
    <a class="<?= $tab === 'pay' ? 'on' : '' ?>" href="/invoices?tab=pay">TO PAY<?= $counts['pay'] ? ' (' . $counts['pay'] . ')' : '' ?></a>
    <a class="<?= $tab === 'receive' ? 'on' : '' ?>" href="/invoices?tab=receive">TO RECEIVE<?= $counts['receive'] ? ' (' . $counts['receive'] . ')' : '' ?></a>
  </div>
  <form method="get" action="/invoices" class="inv-search">
    <input type="hidden" name="tab" value="<?= h($tab) ?>"><input type="hidden" name="sort" value="<?= h($sort) ?>"><input type="hidden" name="dir" value="<?= h($dir) ?>">
    <input type="search" name="q" value="<?= h($search) ?>" placeholder="Search by contract, todo or invoice number">
    <button class="btn small" type="submit">Search</button>
    <?php if ($search !== ''): ?><a class="btn small" href="<?= h($qs(['q' => ''])) ?>">Reset</a><?php endif; ?>
  </form>
  <?php if (!$list): ?><p class="muted"><?= $search !== '' ? 'Nothing found.' : 'No invoices yet.' ?></p><?php else: ?>
  <div class="table-wrap">
  <table class="admin-table inv-table">
    <tr><th><a href="<?= h($qs(['sort' => 'contract', 'dir' => $sort === 'contract' && $dir === 'asc' ? 'desc' : 'asc'])) ?>">Contract<?= $sort === 'contract' ? ($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th><th><a href="<?= h($qs(['sort' => 'date', 'dir' => $sort === 'date' && $dir === 'desc' ? 'asc' : 'desc'])) ?>">Date<?= $sort === 'date' ? ($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th><th>Steps</th><th>Todo name</th><th>Todo №</th><th class="right">Price</th><th>Pay now</th><th>Status</th></tr>
    <?php foreach ($list as $i):
        $sym = CURRENCIES[$i['currency']][0] ?? '₽';
        $dlg = 'pay-' . (int)$i['id']; ?>
      <tr>
        <td><a href="/contract/<?= (int)$i['contract_id'] ?>"><?= h($i['contract_title']) ?></a></td>
        <td><?= h(fdate($i['created_at'])) ?></td>
        <td><?= (int)$i['steps'] ?></td>
        <td><a href="/invoice/<?= (int)$i['id'] ?>" title="View invoice"><?= h($i['todo_name'] ?? 'Whole contract') ?></a></td>
        <td><?= $i['todo_name'] !== null ? (int)$i['todo_pos'] + 1 : '-' ?></td>
        <td class="right"><b><?= h(tax_money((float)tax_for_invoice($i)['total'], $i['currency'])) ?></b></td>
        <td>
          <?php if ($tab === 'pay' && $i['status'] === 'waiting'): ?>
            <a href="#" class="pay-link" data-open="<?= h($dlg) ?>">Pay now</a>
          <?php elseif ($tab === 'receive' && $i['status'] === 'pending'): ?>
            <form method="post" action="/invoice/<?= (int)$i['id'] ?>/confirm" class="inline"><?= csrf_field() ?><button class="btn small green-outline" type="submit">Confirm payment</button></form>
          <?php else: ?>-<?php endif; ?>
        </td>
        <td><span class="pill inv-<?= h($i['status']) ?>"><?= h(INVOICE_STATUS[$i['status']] ?? $i['status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php if ($tab === 'pay'): foreach ($list as $i): if ($i['status'] !== 'waiting') { continue; }
      echo payment_dialog_html($i, 'pay-' . (int)$i['id'], false, '/invoices');
  endforeach; endif; ?>
  <?php endif; ?>
</div>
<?php });
