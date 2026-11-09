<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();
$id = (int)($_GET['id'] ?? 0);

$q = $pdo->prepare("SELECT ord.*, p.title, cl.username AS client_name, cl.tier AS client_tier, cm.username AS comm_name, cm.tier AS comm_tier,
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
$myRating = $theirRating = null;
if ($o['status'] === 'completed') {
  $rq = $pdo->prepare('SELECT stars, comment FROM ratings WHERE order_id=? AND rater_id=?');
  $rq->execute([$id, $me['id']]); $myRating = $rq->fetch();
  if ($myRating) { $rq->execute([$id, $isComm ? $o['client_id'] : $o['commissioner_id']]); $theirRating = $rq->fetch(); } // blind until you rate

  $os      = user_stats($isComm ? $o['client_id'] : $o['commissioner_id']); // the other party, fetched once
  $osTier  = $isComm ? $o['client_tier'] : $o['comm_tier'];
  $tags = $isComm
    ? ['Clear instructions', 'Responsive', 'Fair request', 'Easy to work with']
    : ['On time', 'Great communication', 'Followed instructions', 'Good quality'];
  $faces = [1 => ['Poor',      '<path d="M9 10h.01M15 10h.01"/><path d="M8.5 17.5c.9-1.8 2-2.7 3.5-2.7s2.6.9 3.5 2.7"/>'],
            2 => ['Fair',      '<path d="M9 10h.01M15 10h.01"/><path d="M9 16.2c.9-.8 1.9-1.2 3-1.2s2.1.4 3 1.2"/>'],
            3 => ['Okay',      '<path d="M9 10h.01M15 10h.01"/><path d="M9 15.5h6"/>'],
            4 => ['Great',     '<path d="M9 10h.01M15 10h.01"/><path d="M8.5 14c.9 1.8 2 2.7 3.5 2.7s2.6-.9 3.5-2.7"/>'],
            5 => ['Excellent', '<path d="M9 9.5h.01M15 9.5h.01"/><path d="M8 13.5h8c0 2.6-1.8 4.2-4 4.2s-4-1.6-4-4.2z"/>']];
  $face = fn($n, $px = 34) => '<svg class="rt-ico" width="' . $px . '" height="' . $px . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/>' . $faces[$n][1] . '</svg>';
}
?>
<!doctype html>
<html lang="en"><head><title>Order · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/chat.css"><link rel="stylesheet" href="<?= BASE_URL ?>assets/css/order.css"><link rel="stylesheet" href="<?= BASE_URL ?>assets/css/profile.css"><?php if ($o['status'] === 'completed'): ?><link rel="stylesheet" href="<?= BASE_URL ?>assets/css/orders.css"><link rel="stylesheet" href="<?= BASE_URL ?>assets/css/dash.css"><link rel="stylesheet" href="<?= BASE_URL ?>assets/css/rate.css"><?php endif; ?></head>
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
      <section class="panel rt-time" aria-label="Order progress"><?= order_timeline($o) ?></section>

      <section class="panel rt-card<?= $myRating ? ' done' : '' ?>" id="rate" aria-labelledby="rt-h">
        <div class="rt-strip">
          <span class="rt-flag"><?= $myRating ? 'Done · rating submitted' : 'Last step · rating required' ?></span>
          <span class="rt-left"><?= $myRating ? 'All caught up' : '1 of 1 left' ?></span>
        </div>
        <div class="rt-body">
          <div class="rt-head">
            <span class="rt-av" aria-hidden="true"><?= e(strtoupper(mb_substr($other, 0, 1))) ?></span>
            <div>
              <?php if ($myRating): ?>
                <h2 id="rt-h">Your rating</h2>
                <p class="muted">Thanks. Ratings keep the marketplace honest.</p>
              <?php else: ?>
                <h2 id="rt-h">How was working with <span class="rt-hl"><?= e($other) ?></span>?</h2>
                <p class="muted">You can't post or send offers until you rate.</p>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!$myRating): ?>
            <form method="post" action="order_action.php" class="rt-form" id="rate-form">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="order_id" value="<?= $id ?>">
              <input type="hidden" name="action" value="rate">
              <div class="alert err rt-err" id="rt-err" role="alert" aria-live="polite"<?= $flashErr ? '' : ' hidden' ?>><?= e($flashErr) ?></div>

              <fieldset class="rt-pick">
                <legend class="sr">Rating</legend>
                <div class="rt-faces">
                  <?php foreach ($faces as $n => [$lab]): ?>
                    <label class="rt-face"><input type="radio" name="stars" value="<?= $n ?>" required><span class="rt-tile"><?= $face($n) ?><b><?= e($lab) ?></b></span></label>
                  <?php endforeach; ?>
                </div>
              </fieldset>

              <div class="rt-tags">
                <span class="rt-add">Add:</span>
                <?php foreach ($tags as $t): ?><button type="button" class="qk" data-add="<?= e($t) ?>." aria-pressed="false"><?= e($t) ?></button><?php endforeach; ?>
              </div>

              <div class="field rt-cm">
                <label class="lbl" for="comment">Comment</label>
                <textarea id="comment" name="comment" maxlength="1000" required><?= e($oldComment) ?></textarea>
                <p class="hint rt-hint" id="rt-hint"><span id="rt-rule">At least 10 characters.</span><span id="rt-count"><?= mb_strlen($oldComment) ?> / 1000</span></p>
              </div>

              <div class="rt-prev">
                <small>How it will look on <?= e($other) ?>'s profile</small>
                <div class="rt-pv-top"><span class="rt-pv-face" id="rt-pv-face" aria-hidden="true"></span><b id="rt-pv-label" aria-live="polite">No rating yet</b><span class="muted">by you</span></div>
                <p class="rt-pv-text rt-empty" id="rt-pv-text">Your comment appears here.</p>
              </div>

              <button class="btn rt-submit">Submit rating</button>
            </form>
          <?php else: ?>
            <div class="rt-done">
              <div class="rt-box"><small>You rated <?= e($other) ?></small>
                <div class="rt-bx-top"><?= $face((int)$myRating['stars'], 28) ?><b><?= e($faces[(int)$myRating['stars']][0]) ?></b></div>
                <p><?= nl2br(e($myRating['comment'])) ?></p></div>
              <?php if ($theirRating): ?>
                <div class="rt-box"><small><?= e($other) ?>'s rating of you</small>
                  <div class="rt-bx-top"><?= $face((int)$theirRating['stars'], 28) ?><b><?= e($faces[(int)$theirRating['stars']][0]) ?></b></div>
                  <p><?= nl2br(e($theirRating['comment'])) ?></p></div>
              <?php else: ?>
                <div class="rt-box wait"><small><?= e($other) ?>'s rating of you</small><p>You'll see it once they submit theirs.</p></div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <div class="rt-pay">
        <div class="d-stat"><small><?= $isComm ? 'Client paid' : 'You paid' ?></small><b><?= e(money($o['price'])) ?></b></div>
        <div class="d-stat"><small>Platform fee</small><b>−<?= e(money($o['fee'])) ?></b></div>
        <div class="d-stat hot"><small><?= $isComm ? 'You received' : e($other) . ' got' ?></small><b><?= e(money($payout)) ?></b></div>
      </div>

      <?php if ($o['final_name']): ?>
        <section class="panel rt-file">
          <span class="rt-fi" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/></svg></span>
          <div class="rt-fn"><b><?= e($o['final_name']) ?></b><small>Final file · unlocked after approval</small></div>
          <a class="btn ghost" href="download.php?id=<?= $id ?>">Download</a>
        </section>
      <?php endif; ?>

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

    <?php if ($o['status'] === 'completed'): $tr = trust_score($os); ?>
    <div class="rt-side">
      <section class="panel">
        <h2><?= $isComm ? 'Client' : 'Commissioner' ?></h2>
        <div class="rt-pc">
          <div class="d-rep">
            <div class="d-ring" style="--p:<?= (int)$tr ?>" role="img" aria-label="Trust score <?= (int)$tr ?> out of 100"><b><?= (int)$tr ?></b></div>
            <div class="rt-nm"><b><a href="profile.php?u=<?= urlencode($other) ?>"><?= e($other) ?></a></b>
              <span class="chip tier"><?= e($osTier) ?></span><small>Trust score</small></div>
          </div>
          <div class="d-kv"><span>Rating</span><b><?= $os['rating_n'] ? e(number_format($os['rating'], 1) . ' (' . $os['rating_n'] . ')') : 'No ratings yet' ?></b></div>
          <div class="d-kv"><span>Completed orders</span><b><?= (int)$os['completed'] ?></b></div>
          <?php if (!$isComm): ?><div class="d-kv"><span>On-time</span><b><?= $os['delivered'] ? e(round($os['ontime_pct'] * 100) . '%') : 'No deliveries yet' ?></b></div><?php endif; ?>
          <?php if ($bd = user_badges($os)): ?><div class="d-chips"><?php foreach ($bd as $b): ?><span class="chip"><?= e($b) ?></span><?php endforeach; ?></div><?php endif; ?>
        </div>
      </section>
      <section class="panel">
        <h2>Order details</h2>
        <div class="rt-pc"><dl class="rt-dl">
          <div><dt>Escrow</dt><dd><?= e($escrow) ?></dd></div>
          <div><dt>Due</dt><dd><?= e(date('M j, Y g:i A', strtotime($o['deliver_by']))) ?></dd></div>
          <?php if ($o['delivered_at']): ?><div><dt>Delivered</dt><dd><?= e(date('M j, g:i A', strtotime($o['delivered_at']))) ?></dd></div><?php endif; ?>
          <?php if ($o['completed_at']): ?><div><dt>Completed</dt><dd><?= e(date('M j, g:i A', strtotime($o['completed_at']))) ?></dd></div><?php endif; ?>
          <div><dt>Order</dt><dd>#<?= $id ?></dd></div>
        </dl></div>
      </section>
    </div>
    <?php else: ?>
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
    <?php endif; ?>
  </div>
</main>
<?php if ($hasChat): ?><script src="<?= BASE_URL ?>assets/js/chat.js" defer></script><?php endif; ?>
<?php if ($o['status'] === 'completed' && !$myRating): ?><script src="<?= BASE_URL ?>assets/js/rate.js" defer></script><?php endif; ?>
</body></html>