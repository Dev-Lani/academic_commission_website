<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $q = $pdo->prepare('SELECT * FROM users WHERE username=?');
  $q->execute([trim($_POST['username'] ?? '')]);
  $u = $q->fetch();
  if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
    session_regenerate_id(true);
    $_SESSION['uid'] = $u['id'];
    $_SESSION['role'] = $u['role'];
    header('Location: dashboard.php'); exit;
  }
  $err = 'Username or password is incorrect. Check both and try again.';
}
?>
<!doctype html>
<html lang="en"><head><title>Log in · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?></head>
<body>
<div class="auth">
  <?php include TEMPLATE_PATH . 'auth_side.php'; ?>
  <main class="auth-main"><div class="auth-box">
    <h1>Log in</h1>
    <p class="sub">Welcome back. Pick up where you left off.</p>
    <?php if (isset($_GET['registered'])): ?><div class="alert ok">Account created. You can log in now.</div><?php endif; ?>
    <?php if ($err): ?><div class="alert err" role="alert"><?= e($err) ?></div><?php endif; ?>
    <form method="post">
      <div class="field"><label class="lbl" for="username">Username</label>
        <input type="text" id="username" name="username" autocomplete="username" required autofocus></div>
      <div class="field"><label class="lbl" for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required></div>
      <button class="btn">Log in</button>
    </form>
    <p class="switch">No account yet? <a href="register.php">Create one</a></p>
  </div></main>
</div>
</body></html>