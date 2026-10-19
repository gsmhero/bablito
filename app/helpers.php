<?php
declare(strict_types=1);

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money($n): string
{
    return number_format((float)$n, 0, '.', "\u{00A0}");
}

function fdate(?string $d): string
{
    return $d ? date('d.m.Y', strtotime($d)) : '';
}

function fdatetime(?string $d): string
{
    return $d ? date('d.m.Y | H:i', strtotime($d)) : '';
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function not_found(): void
{
    http_response_code(404);
    render('Not found', fn() => print('<div class="narrow"><h1>404</h1><p>Page not found.</p></div>'));
    exit;
}

function forbidden(): void
{
    http_response_code(403);
    render('Forbidden', fn() => print('<div class="narrow"><h1>403</h1><p>You have no access to this page.</p></div>'));
    exit;
}

/* ---------- CSRF / flash ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        http_response_code(413);
        echo 'The files you selected are too large for this server (limit ' . h((string)ini_get('post_max_size')) . ' per request). Go back and choose smaller files.';
        exit;
    }
    $sent = (string)($_POST['_csrf'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        echo 'Invalid or expired form. Go back, reload the page and try again.';
        exit;
    }
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = [$msg, $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- auth ---------- */

function user(): ?array
{
    static $cache = false;
    if ($cache === false) {
        $cache = null;
        if (!empty($_SESSION['uid'])) {
            $u = row('SELECT * FROM users WHERE id = ? AND blocked = 0', [(int)$_SESSION['uid']]);
            if ($u && $u['pass_hash'] !== '') {
                $cache = $u;
            } else {
                unset($_SESSION['uid']);
            }
        }
    }
    return $cache;
}

function is_admin(): bool
{
    $u = user();
    return $u !== null && $u['role'] === 'admin';
}

function require_login(): array
{
    $u = user();
    if (!$u) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_METHOD'] === 'GET' ? ($_SERVER['REQUEST_URI'] ?? '/') : '/';
        flash('Please log in first.', 'err');
        redirect('/login');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        forbidden();
    }
    return $u;
}

function login_user(int $id): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
}

function safe_local_path(string $p): string
{
    return (preg_match('#^/(?!/)[^\\\\\r\n]*$#', $p)) ? $p : '/';
}

/* ---------- display helpers ---------- */

function stars(float $avg, int $count = 0, bool $showCount = true): string
{
    $full = (int)round($avg);
    $out = '<span class="stars" title="' . h(number_format($avg, 1)) . '">';
    for ($i = 1; $i <= 5; $i++) {
        $out .= $i <= $full ? '&#9733;' : '&#9734;';
    }
    $out .= '</span>';
    if ($showCount) {
        $out .= ' <span class="muted small">(' . $count . ')</span>';
    }
    return $out;
}

function display_name(array $u): string
{
    $n = trim((string)$u['name']);
    return $n !== '' ? $n : 'User #' . $u['id'];
}

/** Name shown to other people respects the "Show in Public" flag. */
function public_name(array $u): string
{
    if (!empty($u['show_name']) || (user() && (user()['id'] == $u['id'] || is_admin()))) {
        return display_name($u);
    }
    return 'User #' . $u['id'];
}

function status_label(string $s): array
{
    return match ($s) {
        'draft' => ['Draft', 'grey'],
        'published' => ['Published', 'green'],
        'active' => ['Active', 'blue'],
        'paused' => ['Paused', 'orange'],
        'fulfilled' => ['Fulfilled', 'green'],
        'terminated' => ['Archived', 'outline'],
        default => [$s, 'grey'],
    };
}

function is_overdue(array $c): bool
{
    $d = $c['agreed_deadline'] ?? $c['deadline'] ?? null;
    return in_array($c['status'], ['active', 'paused'], true) && $d && strtotime($d . ' 23:59:59') < time();
}

function categories(): array
{
    static $c = null;
    return $c ??= rows('SELECT id, name FROM categories ORDER BY name');
}

function setting(string $k, string $default = ''): string
{
    $v = val('SELECT v FROM site_settings WHERE k = ?', [$k]);
    return $v === null ? $default : (string)$v;
}

function recompute_rating(int $userId): void
{
    $r = row('SELECT AVG(rating) a, COUNT(*) c FROM reviews WHERE reviewed_id = ?', [$userId]);
    q('UPDATE users SET rating_avg = ?, rating_count = ? WHERE id = ?', [round((float)($r['a'] ?? 0), 2), (int)$r['c'], $userId]);
}

/* ---------- contracts ---------- */

function contract_totals(int $contractId): array
{
    $r = row('SELECT COALESCE(SUM(price),0) p, COALESCE(SUM(days),0) d, COUNT(*) n FROM todos WHERE contract_id = ?', [$contractId]);
    return ['price' => (float)$r['p'], 'days' => (int)$r['d'], 'steps' => (int)$r['n']];
}

function can_see_contract(array $c): bool
{
    if ($c['status'] === 'published') {
        return true;
    }
    $u = user();
    if (!$u) {
        return false;
    }
    return $u['role'] === 'admin' || (int)$c['user_id'] === (int)$u['id'] || (int)($c['contractor_id'] ?? 0) === (int)$u['id'];
}

function is_participant(array $c): bool
{
    $u = user();
    return $u && ((int)$c['user_id'] === (int)$u['id'] || (int)($c['contractor_id'] ?? 0) === (int)$u['id']);
}

function load_contract(int $id): array
{
    $c = row('SELECT c.*, cat.name AS category, cat.image AS category_image FROM contracts c LEFT JOIN categories cat ON cat.id = c.category_id WHERE c.id = ?', [$id]);
    if (!$c) {
        not_found();
    }
    return $c;
}

function log_comment(int $contractId, string $scope, ?int $offerId, ?int $todoId, string $body, ?int $threadUserId = null): void
{
    $u = user();
    $body = trim($body);
    $hasFiles = !empty($_FILES['attach']['name'][0] ?? '');
    if (($body === '' && !$hasFiles) || mb_strlen($body) > 4000) {
        return;
    }
    q('INSERT INTO comments (contract_id, scope, offer_id, todo_id, thread_user_id, user_id, body) VALUES (?,?,?,?,?,?,?)', [$contractId, $scope, $offerId, $todoId, $threadUserId, $u['id'], $body]);
    if ($hasFiles) {
        save_comment_files((int)db()->lastInsertId());
    }
}

/** Stores files attached to a comment (up to 5, images or documents). */
function save_comment_files(int $commentId): void
{
    $f = $_FILES['attach'];
    $errors = [];
    for ($i = 0, $n = min(count($f['name']), 5); $i < $n; $i++) {
        $one = ['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i], 'size' => $f['size'][$i], 'error' => $f['error'][$i]];
        if ($one['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $res = store_upload($one, 'auto');
        if (is_string($res)) {
            $errors[] = $one['name'] . ': ' . $res;
            continue;
        }
        q('INSERT INTO comment_files (comment_id, kind, stored_name, original_name) VALUES (?,?,?,?)', [$commentId, $res[0], $res[1], $res[2]]);
    }
    if ($errors) {
        flash(implode(' ', $errors), 'err');
    }
}

function attach_button(): string
{
    return '<label class="btn small file-btn attach-btn" title="Attach images or files"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.5l-8.6 8.6a5.5 5.5 0 01-7.8-7.8l9.2-9.2a3.7 3.7 0 015.2 5.2l-9.2 9.2a1.8 1.8 0 01-2.6-2.6l8.5-8.5"/></svg><input type="file" name="attach[]" multiple hidden></label>';
}

function render_comments(array $list, int $authorId, bool $bubbles = false): void
{
    if (!$list) {
        echo '<p class="muted small">No comments yet.</p>';
        return;
    }
    foreach ($list as $cm) {
        $mine = (int)$cm['user_id'] === $authorId;
        $cls = $bubbles ? ($mine ? 'bubble client' : 'bubble contractor') : 'bubble plain';
        echo '<div class="' . $cls . '"><div class="meta"><a href="/user/' . (int)$cm['user_id'] . '">' . h($cm['uname']) . '</a> · ' . h(fdatetime($cm['created_at'])) . '</div>' . nl2br(h($cm['body'])) . comment_attachments($cm['files'] ?? []) . '</div>';
    }
}

function comments_for(string $where, array $params): array
{
    $list = rows("SELECT cm.*, COALESCE(NULLIF(u.name,''), CONCAT('User #', u.id)) AS uname FROM comments cm JOIN users u ON u.id = cm.user_id WHERE $where ORDER BY cm.id", $params);
    if ($list) {
        $by = [];
        foreach (rows('SELECT * FROM comment_files WHERE comment_id IN (' . implode(',', array_map('intval', array_column($list, 'id'))) . ') ORDER BY id') as $f) {
            $by[(int)$f['comment_id']][] = $f;
        }
        foreach ($list as $i => $cm) {
            $list[$i]['files'] = $by[(int)$cm['id']] ?? [];
        }
    }
    return $list;
}

/* ---------- uploads ---------- */

const IMAGE_EXT = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
const FILE_EXT = ['pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'xlsc', 'csv', 'dxf', 'dwg', 'zip', 'rar', '7z', 'ppt', 'pptx', 'odt', 'ods', 'rtf'];

/** Stores one uploaded file, returns [kind, stored_name, original_name] or an error string. */
function store_upload(array $f, string $want): array|string
{
    if ($f['error'] === UPLOAD_ERR_NO_FILE) {
        return 'No file selected.';
    }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        return 'File is larger than the server allows (' . ini_get('upload_max_filesize') . ').';
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return 'Upload failed (code ' . (int)$f['error'] . ').';
    }
    if ($f['size'] > 10 * 1024 * 1024) {
        return 'File is larger than 10 MB.';
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if ($want === 'auto') {
        $want = isset(IMAGE_EXT[$ext]) ? 'image' : 'file';
    }
    if ($want === 'image') {
        if (!isset(IMAGE_EXT[$ext]) || @getimagesize($f['tmp_name']) === false) {
            return 'Only JPG, PNG, GIF or WEBP images are allowed.';
        }
        $kind = 'image';
    } else {
        if (!in_array($ext, FILE_EXT, true)) {
            return 'This file type is not allowed.';
        }
        $kind = 'file';
    }
    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!is_dir(ROOT . '/uploads') || !is_writable(ROOT . '/uploads')) {
        return 'The uploads folder is not writable on the server.';
    }
    if (!move_uploaded_file($f['tmp_name'], ROOT . '/uploads/' . $stored)) {
        return 'Could not save the file.';
    }
    $orig = mb_substr(preg_replace('/[^\p{L}\p{N}._ \-()]/u', '_', basename($f['name'])), 0, 180);
    return [$kind, $stored, $orig];
}

/* ---------- layout ---------- */

function render(string $title, callable $body, array $opts = []): void
{
    $u = user();
    $f = flash();
    $pages = rows('SELECT slug, title FROM static_pages ORDER BY FIELD(slug, "about", "privacy", "disclaimer", "offer"), slug');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · bablito</title>
<link rel="icon" href="/assets/logo.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fira+Sans:wght@300;400;500;600;700&family=Fira+Sans+Extra+Condensed:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css?v=<?= (int)@filemtime(ROOT . '/assets/style.css') ?>">
</head>
<body>
<header class="top">
  <div class="wrap bar">
    <nav class="main-nav">
      <a href="/contracts" class="nav-link">CONTRACTS</a>
      <a href="/contract/new" class="nav-btn">CREATE CONTRACT</a>
    </nav>
    <div class="bar-right">
      <?php if ($u): ?>
        <span class="small"><?= h(display_name($u)) ?></span>
      <?php else: ?>
        <span class="small"><a href="/login">Login</a> | <a href="/register">Register</a></span>
      <?php endif; ?>
      <a href="/watchlater" class="icon-btn" title="Watch later" aria-label="Watch later">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
      </a>
      <div class="menu">
        <button class="icon-btn" type="button" id="menu-btn" aria-label="Menu" aria-expanded="false">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="5" width="18" height="2.4"/><rect x="3" y="10.8" width="18" height="2.4"/><rect x="3" y="16.6" width="18" height="2.4"/></svg>
        </button>
        <div class="menu-pop" id="menu-pop" hidden>
          <?php if ($u): ?>
            <?php if ($u['role'] === 'admin'): ?><a href="/admin" class="green">Admin mode</a><a href="/home">Homepage</a><?php endif; ?>
            <a href="/dashboard">Dashboard</a>
            <a href="/invoices">Invoices</a>
            <a href="/profile">Profile</a>
            <a href="/my">My Contracts</a>
            <a href="/contract/new">Create Contract</a>
            <form method="post" action="/logout"><?= csrf_field() ?><button type="submit" class="linkbtn">Log out</button></form>
          <?php else: ?>
            <a href="/login">Login</a>
            <a href="/register">Register</a>
          <?php endif; ?>
        </div>
      </div>
      <a href="/home" class="logo"><img src="/assets/logo.svg" alt="bablito" height="44"></a>
    </div>
  </div>
</header>
<main>
<?php if ((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) !== '/'): ?>
<div class="wrap"><a href="<?= h($opts['back'] ?? '/contracts') ?>" class="back-link"<?= isset($opts['back']) ? '' : ' data-back' ?>><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg> Back</a></div>
<?php endif; ?>
<?php if ($f): ?><div class="wrap"><div class="flash <?= $f[1] === 'err' ? 'err' : 'ok' ?>"><?= h($f[0]) ?></div></div><?php endif; ?>
<?php $body(); ?>
</main>
<footer class="foot">
  <div class="wrap foot-in">
    <div class="foot-links">
      <?php foreach ($pages as $p): ?><a href="/page/<?= h($p['slug']) ?>"><?= h($p['title']) ?></a><?php endforeach; ?>
    </div>
    <div class="copy"><strong>bablito</strong> by sysfera.ru &nbsp;copyrights <?= date('Y') ?></div>
  </div>
</footer>
<script src="/assets/app.js"></script>
</body>
</html>
<?php
}

function clock_icon(int $size = 16): string
{
    return '<svg class="clock" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
}

/** Adds the running time to the stopwatch total and stops it. */
function stop_timer(int $contractId): void
{
    q('UPDATE timer_log SET stopped_at = NOW(), seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()) WHERE contract_id = ? AND stopped_at IS NULL', [$contractId]);
    q('UPDATE contracts SET timer_seconds = timer_seconds + TIMESTAMPDIFF(SECOND, timer_started_at, NOW()), timer_started_at = NULL WHERE id = ? AND timer_started_at IS NOT NULL', [$contractId]);
}

function comment_attachments(array $files): string
{
    if (!$files) {
        return '';
    }
    $imgs = '';
    $docs = '';
    foreach ($files as $f) {
        $url = '/uploads/' . h($f['stored_name']);
        if ($f['kind'] === 'image') {
            $imgs .= '<figure><a href="' . $url . '" target="_blank"><img src="' . $url . '" alt="" loading="lazy"></a></figure>';
        } else {
            $docs .= '<li><a href="' . $url . '" download="' . h($f['original_name']) . '">' . h($f['original_name']) . '</a></li>';
        }
    }
    return ($imgs ? '<div class="gallery c-gallery">' . $imgs . '</div>' : '') . ($docs ? '<ul class="files c-files">' . $docs . '</ul>' : '');
}

const CURRENCIES = ['RUB' => ['₽', '₽ РУБ'], 'USD' => ['$', '$ USD'], 'EUR' => ['€', '€ EUR']];

function cur_code(array $c): string
{
    $k = $c['currency'] ?? 'RUB';
    return isset(CURRENCIES[$k]) ? $k : 'RUB';
}

function cur_symbol(array $c): string
{
    return CURRENCIES[cur_code($c)][0];
}

/** Planned cost of one work hour: agreed price over agreed working days (8 h each, at least one day). */
function hour_rate(array $c): ?float
{
    if (($c['agreed_price'] ?? null) === null) {
        return null;
    }
    return (float)$c['agreed_price'] / (max(1, (int)($c['agreed_days'] ?? 0)) * 8);
}

function money2($n): string
{
    return number_format((float)$n, 2, '.', "\u{00A0}");
}
