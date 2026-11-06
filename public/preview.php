<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$q = $pdo->prepare("SELECT ord.*, p.title, x.preview_stored, x.preview_mime
  FROM orders ord
  JOIN posts p ON p.id=ord.post_id
  JOIN order_outputs x ON x.order_id=ord.id
  WHERE ord.id=?");
$q->execute([$id]);
$o = $q->fetch();
if (!$o || $o['client_id'] != $me['id']) {
  http_response_code(404); echo 'Preview not found. <a href="orders.php">Back to orders</a>'; exit;
}
$back = 'order.php?id=' . $id;
if ($o['status'] !== 'delivered') { header('Location: ' . $back); exit; }

$now  = time();
$exp  = $o['preview_expires_at'] ? strtotime($o['preview_expires_at']) : 0;
$live = $exp > $now;

/* ---------- Start the window (once only) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (csrf_ok() && !$o['preview_started_at']) {
    $pdo->prepare('UPDATE orders SET preview_started_at=?, preview_expires_at=? WHERE id=? AND preview_started_at IS NULL')
        ->execute([date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + PREVIEW_MIN * 60), $id]);
  }
  header('Location: preview.php?id=' . $id); exit;
}

/* ---------- Stream the file, only while the window is open ---------- */
if (isset($_GET['raw'])) {
  $path = PRIVATE_DIR . $o['preview_stored'];
  if (!$live || !is_file($path)) { http_response_code(403); exit; }
  header('Content-Type: ' . $o['preview_mime']);
  header('Content-Disposition: inline; filename="preview"');
  header('Content-Length: ' . filesize($path));
  header('Cache-Control: no-store, private');
  header('X-Content-Type-Options: nosniff');
  readfile($path); exit;
}

if ($live) {
  $pdo->prepare('INSERT INTO view_logs (order_id, user_id, ip, created_at) VALUES (?,?,?,?)')
      ->execute([$id, $me['id'], $_SERVER['REMOTE_ADDR'] ?? '', date('Y-m-d H:i:s', $now)]);
  $label = e($me['username']) . ' · ' . date('M j, g:i A', $now) . ' · order #' . $id;
  $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"><text x="20" y="100" font-size="16" font-family="sans-serif" '
       . 'fill="rgba(27,35,64,.22)" transform="rotate(-25 160 90)">' . $label . '</text></svg>';
  $wm = 'data:image/svg+xml,' . rawurlencode($svg);
}
?>
<!doctype html>
<html lang="en"><head><title>Preview · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/order.css"></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">
  <div class="page-head">
    <p><a href="<?= e($back) ?>">Back to order</a></p>
    <h1><?= e($o['title']) ?></h1>
  </div>

<?php if ($live): ?>
  <p class="alert ok">View-only preview. It closes <span data-sec="1" data-prefix="in " data-deadline="<?= e(date('c', $exp)) ?>"></span>.
    Every view is logged. Go back to the order to approve or dispute.</p>
  <div class="viewer" oncontextmenu="return false">
    <?php if ($o['preview_mime'] === 'application/pdf'): ?>
      <iframe src="preview.php?id=<?= $id ?>&amp;raw=1#toolbar=0&amp;navpanes=0" title="Preview of the delivered work"></iframe>
    <?php else: ?>
      <img src="preview.php?id=<?= $id ?>&amp;raw=1" alt="Preview of the delivered work" draggable="false">
    <?php endif; ?>
    <div class="wm" style="background-image:url('<?= $wm ?>')"></div>
  </div>
  <script>setTimeout(function () { location.reload(); }, <?= ($exp - $now) * 1000 + 500 ?>);</script>

<?php elseif (!$o['preview_started_at']): ?>
  <section class="panel panel-pad">
    <h2>Open the preview</h2>
    <p>You get <?= PREVIEW_MIN ?> minutes of view-only access, watermarked with your name. The timer starts when you press the button and can't be restarted.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn sm">Start preview</button></form>
  </section>

<?php else: ?>
  <section class="panel panel-pad">
    <h2>Preview window ended</h2>
    <p>Your <?= PREVIEW_MIN ?> minutes are over. Go back to the order to approve the work or open a dispute.</p>
    <a class="btn sm" href="<?= e($back) ?>">Back to order</a>
  </section>
<?php endif; ?>
</main>
</body></html>