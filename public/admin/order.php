<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$me = require_admin();
$id = (int)($_GET['id'] ?? 0);

$q = $pdo->prepare("SELECT ord.*, p.title, cl.username AS client_name, cm.username AS comm_name, cm.tier AS comm_tier,
    d.reason, d.status AS dstatus, d.resolution, d.admin_note, d.created_at AS dispute_at, x.final_name
  FROM orders ord
  JOIN disputes d ON d.order_id=ord.id
  JOIN posts p ON p.id=ord.post_id
  JOIN users cl ON cl.id=ord.client_id
  JOIN users cm ON cm.id=ord.commissioner_id
  LEFT JOIN order_outputs x ON x.order_id=ord.id
  WHERE ord.id=?");
$q->execute([$id]);
$o = $q->fetch();
if (!$o) { http_response_code(404); echo 'Dispute not found. <a href="index.php">Back</a>'; exit; }

$logs = $pdo->prepare('SELECT ip, created_at FROM view_logs WHERE order_id=? ORDER BY id');
$logs->execute([$id]);
$logs = $logs->fetchAll();
$msgs = chat_messages($id, (int)$me['id']);
$late = $o['delivered_at'] && strtotime($o['delivered_at']) > strtotime($o['deliver_by']);
$pct = TIER_FEE[$o['comm_tier']] ?? PLATFORM_FEE_PCT;
?>
<!doctype html>
<html lang="en"><head><title>Dispute · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/chat.css"><link rel="stylesheet" href="<?= BASE_URL ?>assets/css/profile.css"></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">
  <div class="page-head"><p><a href="index.php">Admin</a></p><h1><?= e($o['title']) ?></h1>
    <p><?= e($o['client_name']) ?> vs <?= e($o['comm_name']) ?> · <?= money($o['price']) ?></p></div>

  <div class="layout">
    <div class="admin-grid">
      <section class="panel panel-pad">
        <h2>Client's reason</h2>
        <p><?= nl2br(e($o['reason'])) ?></p>
        <p class="muted">Delivered <?= e(date('M j, g:i A', strtotime($o['delivered_at']))) ?>
          (<?= $late ? 'after' : 'before' ?> the <?= e(date('M j, g:i A', strtotime($o['deliver_by']))) ?> deadline)
          · Preview opened <?= $o['preview_started_at'] ? e(date('M j, g:i A', strtotime($o['preview_started_at']))) : 'never' ?> · File: <?= e($o['final_name']) ?></p>
      </section>

      <section class="panel">
        <h2>Preview view log (<?= count($logs) ?>)</h2>
        <?php if (!$logs): ?><p class="empty">The client never opened the preview.</p><?php endif; ?>
        <ul class="log"><?php foreach ($logs as $l): ?><li><?= e(date('M j, g:i:s A', strtotime($l['created_at']))) ?> · <?= e($l['ip']) ?></li><?php endforeach; ?></ul>
      </section>

      <section class="panel chat">
        <h2>Chat log (read-only)</h2>
        <ul class="chat-log">
          <?php if (!$msgs): ?><li class="muted">No messages.</li><?php endif; ?>
          <?php foreach ($msgs as $m): ?>
            <li class="msg theirs"><div class="bubble"><?= e($m['body']) ?></div><small><?= e($m['name']) ?> · <?= e($m['time']) ?></small></li>
          <?php endforeach; ?>
        </ul>
      </section>

      <section class="panel panel-pad">
        <?php if ($o['dstatus'] === 'open' && $o['status'] === 'disputed'): ?>
          <h2>Decision</h2>
          <form method="post" action="action.php" onsubmit="return confirm('Final decision. Continue?')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="resolve">
            <input type="hidden" name="order_id" value="<?= $id ?>">
            <div class="decision">
              <label><input type="radio" name="decision" value="release" required>
                <span>Work is valid. Pay the commissioner (<?= $pct ?>% fee). Counts as an abusive dispute for the client.</span></label>
              <label><input type="radio" name="decision" value="refund">
                <span>Work is not valid. Refund the client. Counts as a lost dispute for the commissioner (tier drops, badge removed). The request reopens.</span></label>
            </div>
            <div class="field"><label class="lbl" for="note">Note (both sides see the outcome)</label>
              <textarea id="note" name="note" maxlength="1000" required></textarea></div>
            <button class="btn sm">Resolve dispute</button>
          </form>
        <?php else: ?>
          <h2>Resolved</h2>
          <p><?= $o['resolution'] === 'release' ? 'Paid to the commissioner.' : 'Refunded to the client.' ?></p>
          <p class="muted"><?= nl2br(e($o['admin_note'])) ?></p>
        <?php endif; ?>
      </section>
    </div>
    <aside class="panel"><h2>Order</h2><div class="profile"><dl>
      <div><dt>Client</dt><dd><a href="<?= BASE_URL ?>profile.php?u=<?= urlencode($o['client_name']) ?>"><?= e($o['client_name']) ?></a></dd></div>
      <div><dt>Commissioner</dt><dd><a href="<?= BASE_URL ?>profile.php?u=<?= urlencode($o['comm_name']) ?>"><?= e($o['comm_name']) ?></a> (<?= e($o['comm_tier']) ?>)</dd></div>
      <div><dt>Escrow</dt><dd><?= e(ucfirst($o['escrow_status'])) ?></dd></div>
      <div><dt>Dispute opened</dt><dd><?= e(date('M j, g:i A', strtotime($o['dispute_at']))) ?></dd></div>
    </dl></div></aside>
  </div>
</main>
</body></html>