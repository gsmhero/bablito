<?php
declare(strict_types=1);

if (user()) {
    redirect('/');
}
$error = '';
$email = '';
$name = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $name = trim((string)($_POST['name'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $error = 'Enter a valid email address.';
    } elseif ($name === '' || mb_strlen($name) > 120) {
        $error = 'Enter your name.';
    } elseif (mb_strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (strcasecmp($email, ADMIN_EMAIL) === 0 || val('SELECT id FROM users WHERE email = ?', [$email])) {
        $error = 'This email is already registered.';
    } else {
        q('INSERT INTO users (email, pass_hash, name, contact_email, timezone) VALUES (?,?,?,?,?)', [$email, password_hash($pass, PASSWORD_DEFAULT), $name, $email, valid_timezone((string)($_POST['tz'] ?? ''))]);
        login_user((int)db()->lastInsertId());
        flash('Welcome to bablito!');
        redirect('/dashboard');
    }
}

render('Register', function () use ($error, $email, $name) { ?>
<div class="narrow card-form">
  <h1>Register</h1>
  <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="tz" id="tz-field">
    <label>Your name <input name="name" value="<?= h($name) ?>" maxlength="120" required autofocus></label>
    <label>Email <input type="email" name="email" value="<?= h($email) ?>" required></label>
    <label>Password (8+ characters) <input type="password" name="password" minlength="8" required></label>
    <button class="btn green" type="submit">Create account</button>
  </form>
  <p class="muted small">Already registered? <a href="/login">Login</a></p>
</div>
<script>(function(){var f=document.getElementById("tz-field");try{f.value=Intl.DateTimeFormat().resolvedOptions().timeZone||""}catch(e){}})();</script>
<?php });
