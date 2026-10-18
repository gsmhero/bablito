<?php
declare(strict_types=1);

// One-time password setup for the site administrator (admin@bablito.ru).
// The token lives in storage/admin_setup_token.txt on the server and is deleted after use.
$file = ROOT . '/storage/admin_setup_token.txt';
$admin = row('SELECT id, pass_hash FROM users WHERE email = ?', [ADMIN_EMAIL]);
$token = is_file($file) ? trim((string)file_get_contents($file)) : '';
$given = (string)($_GET['token'] ?? $_POST['token'] ?? '');

if (!$admin || $admin['pass_hash'] !== '' || $token === '' || !hash_equals($token, $given)) {
    not_found();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = (string)($_POST['password'] ?? '');
    $p2 = (string)($_POST['password2'] ?? '');
    if (mb_strlen($p1) < 10) {
        $error = 'Password must be at least 10 characters.';
    } elseif ($p1 !== $p2) {
        $error = 'Passwords do not match.';
    } else {
        q('UPDATE users SET pass_hash = ? WHERE id = ?', [password_hash($p1, PASSWORD_DEFAULT), $admin['id']]);
        @unlink($file);
        flash('Admin password set. You can log in now.');
        redirect('/login');
    }
}

render('Admin setup', function () use ($error, $given) { ?>
<div class="narrow card-form">
  <h1>Set admin password</h1>
  <p class="muted small">Account: <?= h(ADMIN_EMAIL) ?></p>
  <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= h($given) ?>">
    <label>New password <input type="password" name="password" minlength="10" required autofocus></label>
    <label>Repeat password <input type="password" name="password2" minlength="10" required></label>
    <button class="btn green" type="submit">Save password</button>
  </form>
</div>
<?php });
