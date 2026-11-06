<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$me = require_admin();

$base = "SELECT d.order_id, d.created_at, d.resolution, p.title, ord.price, cl.username AS client_name, cm.username AS comm_name
  FROM disputes d JOIN orders ord ON ord.id=d.order_id JOIN posts p ON p.id=ord.post_id
  JOIN users cl ON cl.id=ord.client_id JOIN users cm ON cm.id=ord.commissioner_id ";
$open  = $pdo->query($base . "WHERE d.status='open' ORDER BY d.created_at")->fetchAll();
$done  = $pdo->query($base . "WHERE d.status='resolved' ORDER BY d.resolved_at DESC LIMIT 10")->fetchAll();
$users = $pdo->query("SELECT id, username, role, tier, is_verified FROM users WHERE role<>'admin' ORDER BY created_at DESC LIMIT 100")->fetchAll();
$msg = $_GET['msg'] ?? '';
?>
<!doctype html>
<html lang="en"><head><title>Admin · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/profile.css"></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">
  <div class="page-head"><h1>Admin</h1><p>Resolve disputes and verify accounts.</p></div>
  <?php if ($msg === 'resolved'): ?><div class="alert ok">Dispute resolved.</div><?php endif; ?>
  <?php if ($msg === 'recomputed'): ?><div class="alert ok">Tiers recalculated for all users.</div><?php endif; ?>

  <div class="admin-grid">
    <section class="panel">
      <h2>Open disputes (<?= count($open) ?>)</h2>
      <?php if (!$open): ?><p class="empty">No open disputes.</p><?php else: ?>
      <ul class="rows"><?php foreach ($open as $d): ?>
        <li><div><b><?= e($d['title']) ?></b>
          <small><?= e($d['client_name']) ?> vs <?= e($d['comm_name']) ?> · <?= money($d['price']) ?> in escrow · opened <?= e(date('M j, g:i A', strtotime($d['created_at']))) ?></small></div>
          <a class="btn ghost" href="order.php?id=<?= (int)$d['order_id'] ?>">Review</a></li>
      <?php endforeach; ?></ul><?php endif; ?>
    </section>

    <section class="panel">
      <h2>Recently resolved</h2>
      <?php if (!$done): ?><p class="empty">Nothing yet.</p><?php else: ?>
      <ul class="rows"><?php foreach ($done as $d): ?>
        <li><div><b><?= e($d['title']) ?></b>
          <small><?= e($d['client_name']) ?> vs <?= e($d['comm_name']) ?> · <?= $d['resolution'] === 'release' ? 'Paid to commissioner' : 'Refunded to client' ?></small></div>
          <a class="btn ghost" href="order.php?id=<?= (int)$d['order_id'] ?>">View</a></li>
      <?php endforeach; ?></ul><?php endif; ?>
    </section>

    <section class="panel">
      <h2>Accounts</h2>
      <form method="post" action="action.php" style="padding:.8rem 1.25rem;border-bottom:1px solid var(--line)">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <button class="btn ghost" name="action" value="recompute">Recalculate all tiers</button>
      </form>
      <ul class="rows"><?php foreach ($users as $u): ?>
        <li><div><b><a href="<?= BASE_URL ?>profile.php?u=<?= urlencode($u['username']) ?>"><?= e($u['username']) ?></a></b>
          <small><?= e(ucfirst($u['role'])) ?> · <?= e($u['tier']) ?> · <?= $u['is_verified'] ? 'Verified' : 'Not verified' ?></small></div>
          <form method="post" action="action.php" class="inline">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="verify">
            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="value" value="<?= $u['is_verified'] ? 0 : 1 ?>">
            <button class="btn ghost"><?= $u['is_verified'] ? 'Remove verification' : 'Verify' ?></button>
          </form></li>
      <?php endforeach; ?></ul>
    </section>
  </div>
</main>
</body></html>