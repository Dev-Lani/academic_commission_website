<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$err = '';
$old = ['username'=>'','email'=>'','role'=>''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = trim($_POST['username'] ?? '');
  $m = trim($_POST['email'] ?? '');
  $p = $_POST['password'] ?? '';
  $r = $_POST['role'] ?? '';
  $old = ['username'=>$u,'email'=>$m,'role'=>$r];
  if (!preg_match('/^\w{3,30}$/', $u))            $err = 'Username must be 3 to 30 characters: letters, numbers, or underscore.';
  elseif (!filter_var($m, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email address.';
  elseif (strlen($p) < 8)                         $err = 'Password must be at least 8 characters.';
  elseif (!in_array($r, ['client','commissioner'], true)) $err = 'Choose whether you are a client or a commissioner.';
  else {
    $q = $pdo->prepare('SELECT id FROM users WHERE username=? OR email=?');
    $q->execute([$u, $m]);
    if ($q->fetch()) $err = 'That username or email is already registered. Try logging in instead.';
    else {
      $pdo->prepare('INSERT INTO users (username,email,password_hash,role,tier) VALUES (?,?,?,?,?)')
          ->execute([$u, $m, password_hash($p, PASSWORD_DEFAULT), $r, $r === 'commissioner' ? 'Bronze' : 'New']);
      header('Location: login.php?registered=1'); exit;
    }
  }
}
?>
<!doctype html>
<html lang="en"><head><title>Create account · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?></head>
<body>
<div class="auth">
  <?php include TEMPLATE_PATH . 'auth_side.php'; ?>
  <main class="auth-main"><div class="auth-box">
    <h1>Create account</h1>
    <p class="sub">Choose your role first. You can't change it later.</p>
    <?php if ($err): ?><div class="alert err" role="alert"><?= e($err) ?></div><?php endif; ?>
    <form method="post">
      <div class="field">
        <div class="roles" role="radiogroup" aria-label="Role">
          <label class="role"><input type="radio" name="role" value="client" required <?= $old['role']==='client'?'checked':'' ?>>
            <span><b>Client</b><small>Nagpapagawa</small></span></label>
          <label class="role"><input type="radio" name="role" value="commissioner" <?= $old['role']==='commissioner'?'checked':'' ?>>
            <span><b>Commissioner</b><small>Gumagawa</small></span></label>
        </div>
      </div>
      <div class="field"><label class="lbl" for="username">Username</label>
        <input type="text" id="username" name="username" value="<?= e($old['username']) ?>" autocomplete="username" required></div>
      <div class="field"><label class="lbl" for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" autocomplete="email" required>
        <p class="hint">A school email helps you get verified later.</p></div>
      <div class="field"><label class="lbl" for="password">Password</label>
        <input type="password" id="password" name="password" minlength="8" autocomplete="new-password" required>
        <p class="hint">At least 8 characters.</p></div>
      <button class="btn">Create account</button>
    </form>
    <p class="switch">Already registered? <a href="login.php">Log in</a></p>
  </div></main>
</div>
</body></html>