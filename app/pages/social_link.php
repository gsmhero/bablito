<?php
declare(strict_types=1);

// A person who already has a bablito.ru account connects it to this VK / OK identity.
$u = require_login();
$plat = embed_platform();
if ($plat === '') {
    forbidden();
}
$col = $plat === 'vk' ? 'vk_id' : 'ok_id';
$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $fails = (int)val('SELECT COUNT(*) FROM login_attempts WHERE created_at > (NOW() - INTERVAL 15 MINUTE) AND (email = ? OR ip = ?)', [$email, $ip]);
    $target = row('SELECT * FROM users WHERE email = ?', [$email]);
    $busy = (int)val('SELECT (SELECT COUNT(*) FROM contracts WHERE user_id = ?) + (SELECT COUNT(*) FROM offers WHERE user_id = ?)', [$u['id'], $u['id']]);
    if ($fails >= 10) {
        $error = 'Too many failed attempts. Try again in 15 minutes.';
    } elseif (!$target || $target['pass_hash'] === '' || $target['blocked'] || !password_verify($pass, $target['pass_hash'])) {
        q('INSERT INTO login_attempts (email, ip) VALUES (?, ?)', [$email, $ip]);
        $error = 'Wrong email or password.';
    } elseif (!empty($target[$col]) || (int)$target['id'] === (int)$u['id']) {
        $error = 'This account is already linked.';
    } elseif ($busy > 0) {
        $error = 'This VK / OK profile already has contracts or offers, so it cannot be merged. Write to us and we will help.';
    } else {
        $ext = (string)$u[$col];
        $pdo = db();
        $pdo->beginTransaction();
        // the empty temporary profile gives the identity away and is blocked
        q("UPDATE users SET $col = NULL, blocked = 1 WHERE id = ?", [$u['id']]);
        q("UPDATE users SET $col = ? WHERE id = ?", [$ext, $target['id']]);
        $pdo->commit();
        login_user((int)$target['id']);
        $_SESSION['embed'] = $plat;
        flash('Account linked.');
        redirect('/dashboard');
    }
}

render('Link account', function () use ($error, $email) { ?>
<div class="narrow card-form">
  <h1>Link account</h1>
  <p class="muted small">Already registered on bablito.ru? Enter your e-mail and password, and this application will open your account.</p>
  <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
  <form method="post" action="<?= h(embed_url('/social/link')) ?>">
    <?= csrf_field() ?>
    <label>Email <input type="email" name="email" value="<?= h($email) ?>" required></label>
    <label>Password <input type="password" name="password" required></label>
    <button class="btn green" type="submit">Link account</button>
  </form>
</div>
<?php });
