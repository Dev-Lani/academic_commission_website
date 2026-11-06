<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();
$id = (int)($_GET['id'] ?? 0);

$q = $pdo->prepare("SELECT ord.*, p.title, cl.username AS client_name, cm.username AS comm_name, cm.tier AS comm_tier,
    x.final_name,
    (SELECT COUNT(*) FROM view_logs v WHERE v.order_id=ord.id) AS views
  FROM orders ord
  JOIN posts p ON p.id=ord.post_id
  JOIN users cl ON cl.id=ord.client_id
  JOIN users cm ON cm.id=ord.commissioner_id
  LEFT JOIN order_outputs x ON x.order_id=ord.id
  WHERE ord.id=?");
$q->execute([$id]);
$o = $q->fetch();
if (!$o || ($o['client_id'] != $me['id'] && $o['commissioner_id'] != $me['id'])) {
  http_response_code(404); echo 'Order not found. <a href="orders.php">Back to orders</a>'; exit;
}
$pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=? AND order_id=?')->execute([$me['id'], $id]);

$isComm = $o['commissioner_id'] == $me['id'];
$other  = $isComm ? $o['client_name'] : $o['comm_name'];
$hasChat = in_array($o['status'], ['active', 'delivered', 'disputed', 'completed'], true);

$flashErr  = $_SESSION['flash_err'] ?? '';
$oldReason = $_SESSION['old_reason'] ?? '';
$oldComment = $_SESSION['old_comment'] ?? '';
unset($_SESSION['flash_err'], $_SESSION['old_reason'], $_SESSION['old_comment']);

$dispute = null;
if ($o['status'] === 'disputed') {
  $d = $pdo->prepare('SELECT reason, created_at FROM disputes WHERE order_id=?');
  $d->execute([$id]);
  $dispute = $d->fetch();
}
$escrow = ['none'=>'Not funded', 'held'=>'Held in escrow', 'released'=>'Released', 'refunded'=>'Refunded'][$o['escrow_status']] ?? $o['escrow_status'];
$payout = (int)$o['price'] - (int)$o['fee'];
$feePct = $o['price'] ? round($o['fee'] * 100 / $o['price']) : 0;
$myRating = $theirRating = null;
if ($o['status'] === 'completed') {
  $rq = $pdo->prepare('SELECT stars, comment FROM ratings WHERE order_id=? AND rater_id=?');
  $rq->execute([$id, $me['id']]); $myRating = $rq->fetch();
  if ($myRating) { $rq->execute([$id, $isComm ? $o['client_id'] : $o['commissioner_id']]); $theirRating = $rq->fetch(); } // blind until you rate
}
?>
<!doctype html>
<html lang="en"><head><title>Order · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/chat.css"><link rel="stylesheet" href="<?= BASE_URL ?>assets/css/order.css"><link rel="stylesheet" href="<?= BASE_URL ?>assets/css/profile.css"></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">
  <div class="page-head">
    <p><a href="orders.php">My orders</a></p>
    <h1><?= e($o['title']) ?></h1>
    <p><?= status_tag($o['status']) ?></p>
  </div>

  <div class="layout">
    <div>
    <?php if ($o['status'] === 'pending_agree'): ?>
      <section class="panel panel-pad">
        <?php if ($isComm): ?>
          <h2>Confirm this order</h2>
          <?php if ($flashErr): ?><div class="alert err" role="alert"><?= e($flashErr) ?></div><?php endif; ?>
          <p><?= e($o['client_name']) ?> accepted your offer of <?= money($o['price']) ?>, delivery by
            <?= e(date('M j, g:i A', strtotime($o['deliver_by']))) ?>. Confirm so the work can start.</p>
          <p class="muted">Time left to respond</p>
          <p class="clock" data-sec="1" data-deadline="<?= e(date('c', strtotime($o['agree_by']))) ?>"></p>
          <form method="post" action="order_action.php" class="actions">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="order_id" value="<?= $id ?>">
            <button class="btn sm" name="action" value="agree">Agree and start</button>
            <button class="btn ghost" name="action" value="decline" onclick="return confirm('Decline this order?')">Decline</button>
          </form>
        <?php else: ?>
          <h2>Waiting for <?= e($o['comm_name']) ?></h2>
          <p>They need to confirm before the work starts. If they decline or don't respond in time,
            your request goes back to open and you can pick another offer.</p>
          <p class="muted">Time left for them to respond</p>
          <p class="clock" data-sec="1" data-deadline="<?= e(date('c', strtotime($o['agree_by']))) ?>"></p>
        <?php endif; ?>
      </section>

    <?php elseif ($o['status'] === 'active'): ?>
      <section class="panel panel-pad">
        <h2>Work in progress</h2>
        <div class="timers">
          <div><p class="muted">Time elapsed</p>
            <p class="clock" data-since="<?= e(date('c', strtotime($o['started_at']))) ?>"></p></div>
          <div><p class="muted">Time until delivery</p>
            <p class="clock" data-sec="1" data-deadline="<?= e(date('c', strtotime($o['deliver_by']))) ?>"></p></div>
        </div>
        <?php if ($isComm): ?>
          <h3>Deliver the work</h3>
          <p class="muted">Upload the final file and a preview (PDF, PNG, or JPG, max 10 MB each). The client sees only the preview, view-only and for <?= PREVIEW_MIN ?> minutes. The final file unlocks when they approve.</p>
          <?php if ($flashErr): ?><div class="alert err" role="alert"><?= e($flashErr) ?></div><?php endif; ?>
          <form method="post" action="deliver.php" enctype="multipart/form-data" class="deliver-form"
                onsubmit="return confirm('Deliver now? You can\'t replace the files afterwards.')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="order_id" value="<?= $id ?>">
            <div class="field"><label class="lbl" for="final">Final file</label>
              <input type="file" id="final" name="final" required></div>
            <div class="field"><label class="lbl" for="preview">Preview file</label>
              <input type="file" id="preview" name="preview" accept=".pdf,.png,.jpg,.jpeg" required>
              <p class="hint">Tip: a watermarked or partial PDF of the final output works well.</p></div>
            <button class="btn sm">Deliver work</button>
          </form>
        <?php else: ?>
          <p class="muted"><?= money($o['price']) ?> is held in escrow. It is only released when you approve the work.</p>
        <?php endif; ?>
      </section>

    <?php elseif ($o['status'] === 'delivered'): ?>
      <section class="panel panel-pad">
        <?php if ($isComm): ?>
          <h2>Delivered, waiting for review</h2>
          <p>The client can preview the work for <?= PREVIEW_MIN ?> minutes, then approve or dispute. If they do nothing, payment is released automatically.</p>
          <p class="muted">Review window ends in</p>
          <p class="clock" data-sec="1" data-deadline="<?= e(date('c', strtotime($o['review_by']))) ?>"></p>
          <p class="muted"><?= $o['preview_started_at']
            ? 'The client opened the preview (' . e(plural((int)$o['views'], 'view', 'views')) . ' logged).'
            : 'The client has not opened the preview yet.' ?></p>
        <?php else: ?>
          <h2>Review the work</h2>
          <p><?= e($o['comm_name']) ?> delivered. Open the preview, then approve or dispute. Approving releases <?= money($o['price']) ?> and unlocks the final file.
            If you do nothing, payment is released automatically when the review window ends.</p>
          <p class="muted">Review window ends in</p>
          <p class="clock" data-sec="1" data-deadline="<?= e(date('c', strtotime($o['review_by']))) ?>"></p>
          <?php if ($flashErr): ?><div class="alert err" role="alert"><?= e($flashErr) ?></div><?php endif; ?>
          <div class="review-actions">
            <div class="actions">
              <a class="btn sm" href="preview.php?id=<?= $id ?>"><?= $o['preview_started_at'] ? 'Open preview page' : 'Open preview' ?></a>
              <form method="post" action="order_action.php">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="order_id" value="<?= $id ?>">
                <button class="btn sm" name="action" value="approve" onclick="return confirm('Approve and release payment? This can\'t be undone.')">Approve and release payment</button>
              </form>
            </div>
            <form method="post" action="order_action.php" class="dispute-form">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="order_id" value="<?= $id ?>">
              <div class="field"><label class="lbl" for="reason">Dispute the work</label>
                <textarea id="reason" name="reason" maxlength="1000" <?= $o['preview_started_at'] ? '' : 'disabled' ?>><?= e($oldReason) ?></textarea>
                <p class="hint"><?= $o['preview_started_at']
                  ? 'Say what is wrong. An admin reviews it and your payment stays in escrow.'
                  : 'Open the preview first. You can only dispute work you have looked at.' ?></p></div>
              <button class="btn sm danger" name="action" value="dispute" <?= $o['preview_started_at'] ? '' : 'disabled' ?>
                onclick="return confirm('Send this to admin review?')">Open dispute</button>
            </form>
          </div>
        <?php endif; ?>
      </section>

    <?php elseif ($o['status'] === 'disputed'): ?>
      <section class="panel panel-pad">
        <h2>Under admin review</h2>
        <p><?= money($o['price']) ?> stays in escrow until an admin decides. Keep using the chat if you need to clarify anything. The log is kept as evidence.</p>
        <?php if ($dispute): ?>
          <p class="muted">Reason given on <?= e(date('M j, g:i A', strtotime($dispute['created_at']))) ?></p>
          <p><?= nl2br(e($dispute['reason'])) ?></p>
        <?php endif; ?>
      </section>

    <?php elseif ($o['status'] === 'completed'): ?>
      <section class="panel panel-pad">
        <h2>Completed</h2>
        <dl class="money">
          <div><dt>Price</dt><dd><?= money($o['price']) ?></dd></div>
          <div><dt>Platform fee (<?= $feePct ?>%)</dt><dd>−<?= money($o['fee']) ?></dd></div>
          <div class="total"><dt>Commissioner receives</dt><dd><?= money($payout) ?></dd></div>
        </dl>
        <?php if ($o['final_name']): ?>
          <a class="btn sm" href="download.php?id=<?= $id ?>">Download <?= e($o['final_name']) ?></a>
        <?php endif; ?>
        <h3 style="margin-top:1.5rem">Rate <?= e($other) ?></h3>
        <?php if (!$myRating): ?>
          <p class="muted">Required. You can't post requests or send offers until you rate. The comment shows on <?= e($other) ?>'s profile.</p>
          <?php if ($flashErr): ?><div class="alert err" role="alert"><?= e($flashErr) ?></div><?php endif; ?>
          <form method="post" action="order_action.php" class="rate-form">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="order_id" value="<?= $id ?>">
            <input type="hidden" name="action" value="rate">
            <div class="field"><label class="lbl" for="stars">Rating</label>
              <select id="stars" name="stars" required>
                <option value="">Choose</option>
                <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $i ?> <?= $i == 1 ? 'star' : 'stars' ?></option><?php endfor; ?>
              </select></div>
            <div class="field"><label class="lbl" for="comment">Comment</label>
              <textarea id="comment" name="comment" maxlength="1000" required><?= e($oldComment) ?></textarea>
              <p class="hint">Ratings below 3 stars need a reason (30+ characters).</p></div>
            <button class="btn sm">Submit rating</button>
          </form>
        <?php else: ?>
          <p>You gave <b><?= (int)$myRating['stars'] ?>/5</b>.</p>
          <?php if ($theirRating): ?><p class="muted"><?= e($other) ?> rated you <?= (int)$theirRating['stars'] ?>/5.</p>
          <?php else: ?><p class="muted">You will see <?= e($other) ?>'s rating once they submit it.</p><?php endif; ?>
        <?php endif; ?>
      </section>

    <?php else: ?>
      <section class="panel panel-pad">
        <h2><?= $o['status'] === 'expired' ? 'No response in time' : e(ucfirst($o['status'])) ?></h2>
        <p>This order did not go ahead.
          <?= $isComm ? '' : 'Your request is open again, so you can pick another offer.' ?></p>
        <a class="btn ghost" href="post.php?id=<?= (int)$o['post_id'] ?>">Back to the request</a>
      </section>
    <?php endif; ?>

    <?php if ($hasChat) echo chat_panel($o, $me['id']); ?>
    </div>

    <aside class="panel">
      <h2>Order details</h2>
      <div class="profile"><dl>
        <div><dt><?= $isComm ? 'Client' : 'Commissioner' ?></dt><dd><a href="profile.php?u=<?= urlencode($other) ?>"><?= e($other) ?></a></dd></div>
        <div><dt>Price</dt><dd><?= money($o['price']) ?></dd></div>
        <?php if ($o['escrow_status'] !== 'none'): ?>
          <div><dt>Escrow</dt><dd><?= e($escrow) ?></dd></div>
        <?php endif; ?>
        <div><dt>Deliver by</dt><dd><?= e(date('M j, Y g:i A', strtotime($o['deliver_by']))) ?></dd></div>
        <div><dt>Accepted</dt><dd><?= e(date('M j, g:i A', strtotime($o['accepted_at']))) ?></dd></div>
        <?php if ($o['started_at']): ?>
          <div><dt>Started</dt><dd><?= e(date('M j, g:i A', strtotime($o['started_at']))) ?></dd></div>
        <?php endif; ?>
        <?php if ($o['delivered_at']): ?>
          <div><dt>Delivered</dt><dd><?= e(date('M j, g:i A', strtotime($o['delivered_at']))) ?></dd></div>
        <?php endif; ?>
        <?php if ($o['completed_at']): ?>
          <div><dt>Completed</dt><dd><?= e(date('M j, g:i A', strtotime($o['completed_at']))) ?></dd></div>
        <?php endif; ?>
      </dl></div>
    </aside>
  </div>
</main>
<?php if ($hasChat): ?><script src="<?= BASE_URL ?>assets/js/chat.js" defer></script><?php endif; ?>
</body></html>