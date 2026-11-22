<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$err = '';
$old = ['username'=>'','email'=>'','role'=>''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u  = trim($_POST['username'] ?? '');
  $m  = trim($_POST['email'] ?? '');
  $p  = $_POST['password'] ?? '';
  $p2 = $_POST['password2'] ?? '';
  $r  = $_POST['role'] ?? '';
  $old = ['username'=>$u,'email'=>$m,'role'=>$r];
  if (!preg_match('/^\w{3,30}$/', $u))            $err = 'Username must be 3 to 30 characters: letters, numbers, or underscore.';
  elseif (!filter_var($m, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email address.';
  elseif (strlen($p) < 8)                         $err = 'Password must be at least 8 characters.';
  elseif ($p !== $p2)                             $err = 'Password mismatch. Type again';
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
$ic = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
?>
<!doctype html>
<html lang="en"><head><title>Create account · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/auth.css"></head>
<body class="auth-page">
<div class="ap">
  <header class="ap-top"><a class="brand" href="<?= BASE_URL ?>login.php">Academic Commission</a></header>
  <main class="ap-main">
    <div class="ap-card wide">
      <span class="ap-mark" aria-hidden="true">AC</span>
      <h1>Create account</h1>
      <p class="ap-sub">Pick your role first. You can't change it later.</p>
      <?php if ($err): ?><div class="alert err" role="alert"><?= e($err) ?></div><?php endif; ?>
      <form method="post">
        <div class="field">
          <div class="ap-roles" role="radiogroup" aria-label="Role">
            <label class="ap-role"><input type="radio" name="role" value="client" required <?= $old['role']==='client'?'checked':'' ?>>
              <span><span class="ap-ri"><svg width="20" height="20" <?= $ic ?>><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg></span>
                <span class="ap-rt"><b>Client</b><small>I need work done</small></span><i class="ap-dot"></i></span></label>
            <label class="ap-role"><input type="radio" name="role" value="commissioner" <?= $old['role']==='commissioner'?'checked':'' ?>>
              <span><span class="ap-ri"><svg width="20" height="20" <?= $ic ?>><path d="M4 20l1-4L16.5 4.5a2 2 0 0 1 3 3L8 19z"/><path d="M14 7l3 3"/></svg></span>
                <span class="ap-rt"><b>Commissioner</b><small>I do the work</small></span><i class="ap-dot"></i></span></label>
          </div>
        </div>
        <div class="field"><label class="lbl" for="username">Username</label>
          <div class="ap-inp">
            <svg class="ic" <?= $ic ?>><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
            <input type="text" id="username" name="username" value="<?= e($old['username']) ?>" autocomplete="username" required>
          </div></div>
        <div class="field"><label class="lbl" for="email">Email</label>
          <div class="ap-inp">
            <svg class="ic" <?= $ic ?>><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
            <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" autocomplete="email" required>
          </div>
          <p class="hint">A school email helps you get verified later.</p></div>
        <div class="ap-pair">
          <div class="field"><label class="lbl" for="password">Password</label>
            <div class="ap-inp has-show pw" id="pw">
              <svg class="ic" <?= $ic ?>><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
              <input type="password" id="password" name="password" minlength="8" autocomplete="new-password" required>
              <button type="button" class="ap-show" data-for="password" aria-pressed="false">Show</button>
              <svg class="pw-line" aria-hidden="true"><rect pathLength="1"/></svg>
            </div>
            <p class="hint" id="pw-hint" aria-live="polite">At least 8 characters.</p></div>
          <div class="field"><label class="lbl" for="password2">Confirm password</label>
            <div class="ap-inp">
              <svg class="ic" <?= $ic ?>><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
              <input type="password" id="password2" name="password2" minlength="8" autocomplete="new-password" required>
            </div>
            <p class="hint">Retype your password.</p></div>
        </div>
        <button class="btn">Create account</button>
      </form>
      <p class="ap-switch">Already registered? <a href="login.php">Log in</a></p>
    </div>
    <p class="ap-note"><svg width="18" height="18" <?= $ic ?>><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg>Payments are held in escrow until you approve the work.</p>
  </main>
</div>
<script src="<?= BASE_URL ?>assets/js/pw.js" defer></script>
<script src="<?= BASE_URL ?>assets/js/auth.js" defer></script>
</body></html>