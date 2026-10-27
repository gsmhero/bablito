<?php
declare(strict_types=1);

if (user()) {
    redirect('/');
}
$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $fails = (int)val('SELECT COUNT(*) FROM login_attempts WHERE created_at > (NOW() - INTERVAL 15 MINUTE) AND (email = ? OR ip = ?)', [$email, $ip]);
    if ($fails >= 10) {
        $error = 'Too many failed attempts. Try again in 15 minutes.';
    } else {
        $u = row('SELECT * FROM users WHERE email = ?', [$email]);
        $ok = $u && $u['pass_hash'] !== '' && !$u['blocked'] && password_verify($pass, $u['pass_hash']);
        if ($ok) {
            q('DELETE FROM login_attempts WHERE email = ?', [$email]);
            login_user((int)$u['id']);
            q('UPDATE users SET lang = ? WHERE id = ?', [current_lang(), $u['id']]);
            $to = safe_local_path((string)($_SESSION['after_login'] ?? '/dashboard'));
            unset($_SESSION['after_login']);
            redirect($to);
        }
        q('INSERT INTO login_attempts (email, ip) VALUES (?, ?)', [$email, $ip]);
        $error = 'Wrong email or password.';
    }
}

render('Login', function () use ($error, $email) { ?>
<div class="narrow card-form">
  <h1>Login</h1>
  <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Email <input type="email" name="email" value="<?= h($email) ?>" required autofocus></label>
    <label>Password <input type="password" name="password" required></label>
    <button class="btn green" type="submit">Login</button>
  </form>
  <p class="muted small">No account? <a href="/register">Register</a></p>
</div>
<?php });
