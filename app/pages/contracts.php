<?php
declare(strict_types=1);

require_once ROOT . '/app/card.php';

$sort = ($_GET['sort'] ?? 'date') === 'price' ? 'price' : 'date';
$cat = (int)($_GET['category'] ?? 0);
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));

$where = ["c.status = 'published'"];
$params = [];
if ($cat > 0) {
    $where[] = 'c.category_id = ?';
    $params[] = $cat;
}
if ($from !== '' && is_numeric($from)) {
    $where[] = 't.total_price >= ?';
    $params[] = $from;
}
if ($to !== '' && is_numeric($to)) {
    $where[] = 't.total_price <= ?';
    $params[] = $to;
}
$order = $sort === 'price' ? 't.total_price DESC' : 'c.created_at DESC';

$page = max(1, (int)($_GET['page'] ?? 1));
$per = 20;
$list = rows(listing_select() . ' WHERE ' . implode(' AND ', $where) . " ORDER BY $order, c.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
$total = (int)val('SELECT COUNT(*) FROM contracts c LEFT JOIN ' . TOTALS_SQL . ' t ON t.contract_id = c.id WHERE ' . implode(' AND ', $where), $params);
$uid = user()['id'] ?? null;

render('Contracts', function () use ($list, $sort, $cat, $from, $to, $uid, $total, $page, $per) { ?>
<div class="wrap rel">
  <form class="filters" method="get">
    <label class="f-sort">Sort by
      <select name="sort" onchange="this.form.submit()">
        <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>Date</option>
        <option value="price" <?= $sort === 'price' ? 'selected' : '' ?>>Price</option>
      </select>
    </label>
    <label class="f-cat">Category
      <select name="category" onchange="this.form.submit()">
        <option value="0">Select</option>
        <?php foreach (categories() as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label class="f-from">Price from <input name="from" inputmode="numeric" value="<?= h($from) ?>"></label>
    <label class="f-to">to <input name="to" inputmode="numeric" value="<?= h($to) ?>"></label>
    <button class="btn f-apply" type="submit">Apply</button>
  </form>

  <?php if (!$list): ?><p class="muted">No contracts found.</p><?php endif; ?>
  <?php foreach ($list as $c) { listing_card($c, $uid); } ?>

  <?php if ($total > $per): ?>
  <div class="pager">
    <?php for ($i = 1; $i <= (int)ceil($total / $per); $i++): ?>
      <a class="<?= $i === $page ? 'cur' : '' ?>" href="?<?= h(http_build_query(['sort' => $sort, 'category' => $cat, 'from' => $from, 'to' => $to, 'page' => $i])) ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>
<?php }, ['filters' => true]);
