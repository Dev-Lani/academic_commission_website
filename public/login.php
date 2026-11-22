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
<html lang="en"><head><title>Log in · Doable</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/auth.css"></head>
<body class="auth-page">
<div class="ap">
  <main class="ap-main">
    <div class="ap-card">
      <img class="ap-logo" src="<?= BASE_URL ?>assets/img/logo_nav.png" alt="Doable" height="40">
      <h1>Log in</h1>
      <p class="ap-sub">Welcome back. Enter your details to continue.</p>
      <?php if (isset($_GET['registered'])): ?><div class="alert ok">Account created. You can log in now.</div><?php endif; ?>
      <?php if ($err): ?><div class="alert err" role="alert"><?= e($err) ?></div><?php endif; ?>
      <form method="post">
        <div class="field"><label class="lbl" for="username">Username</label>
          <div class="ap-inp">
            <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
            <input type="text" id="username" name="username" autocomplete="username" required autofocus>
          </div></div>
        <div class="field"><label class="lbl" for="password">Password</label>
          <div class="ap-inp has-show">
            <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
            <button type="button" class="ap-show" data-for="password" aria-pressed="false">Show</button>
          </div></div>
        <button class="btn">Log in</button>
      </form>
      <p class="ap-switch">No account yet? <a href="register.php">Create one</a></p>
    </div>
    <p class="ap-note"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg>Payments are held in escrow until you approve the work.</p>
  </main>
</div>
<script src="<?= BASE_URL ?>assets/js/auth.js" defer></script>
</body></html>