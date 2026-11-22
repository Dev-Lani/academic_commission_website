<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$me = require_admin();
$id = (int)($_GET['id'] ?? 0);

$q = $pdo->prepare("SELECT ord.*, p.title, cl.username AS client_name, cm.username AS comm_name, cm.tier AS comm_tier,
    d.reason, d.status AS dstatus, d.resolution, d.admin_note, d.created_at AS dispute_at, d.resolved_at, x.final_name
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

// Chat log read straight from the table so each bubble can be placed by role (client left, commissioner right).
$mq = $pdo->prepare('SELECT m.sender_id, m.body, m.created_at, u.username FROM order_messages m JOIN users u ON u.id=m.sender_id WHERE m.order_id=? ORDER BY m.id');
$mq->execute([$id]);
$msgs = $mq->fetchAll();

$revs = $pdo->prepare('SELECT round_no, notes, created_at, due_by, fulfilled_at FROM revision_requests WHERE order_id=? ORDER BY round_no');
$revs->execute([$id]);
$revs = $revs->fetchAll();

$late   = $o['delivered_at'] && strtotime($o['delivered_at']) > strtotime($o['deliver_by']);
$pct    = TIER_FEE[$o['comm_tier']] ?? PLATFORM_FEE_PCT;
$fee    = (int)round($o['price'] * $pct / 100);
$payout = (int)$o['price'] - $fee;
$isOpen = $o['dstatus'] === 'open' && $o['status'] === 'disputed';
$auto   = strpos((string)$o['reason'], 'Automatic:') === 0;
$fmt    = fn($ts) => date('M j, g:i A', strtotime($ts));

// One timeline from every dated fact on this order
$ev = [];
$add = function ($ts, $label, $tone = '') use (&$ev) { if ($ts) $ev[] = [strtotime($ts), $label, $tone]; };
$add($o['accepted_at'], 'Client accepted the offer');
$add($o['started_at'], 'Commissioner agreed, escrow held');
$add($o['delivered_at'], 'Work delivered' . ($late ? ' (late)' : ' (on time)'), $late ? 'warn' : '');
foreach ($revs as $rv) {
  $add($rv['created_at'], 'Revision round ' . (int)$rv['round_no'] . ' requested');
  $add($rv['fulfilled_at'], 'Revision round ' . (int)$rv['round_no'] . ' delivered');
}
$add($o['preview_started_at'], 'Client opened the preview');
$add($o['dispute_at'], $auto ? 'Dispute opened automatically' : 'Client opened the dispute', 'bad');
if ($o['resolved_at']) $add($o['resolved_at'], 'Admin resolved the dispute', 'ok');
usort($ev, fn($a, $b) => $a[0] <=> $b[0]);

function ao_ago($ts){
  $s = max(0, time() - strtotime($ts));
  if ($s < 3600)  return max(1, intdiv($s, 60)) . 'm';
  if ($s < 86400) return intdiv($s, 3600) . 'h';
  return intdiv($s, 86400) . 'd';
}
?>
<!doctype html>
<html lang="en"><head><title>Dispute · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin_order.css"></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">

  <a class="ad-back" href="index.php">&larr; All disputes</a>

  <section class="ad-hero ao-hero">
    <div class="ad-hero-t">
      <span class="ao-state <?= $isOpen ? 'open' : 'done' ?>"><?= $isOpen ? 'Open for ' . e(ao_ago($o['dispute_at'])) : 'Resolved' ?></span>
      <h1><?= e($o['title']) ?></h1>
      <div class="ao-vs">
        <a href="<?= BASE_URL ?>profile.php?u=<?= urlencode($o['client_name']) ?>"><span class="ao-av"><?= e(strtoupper(mb_substr($o['client_name'], 0, 1))) ?></span><?= e($o['client_name']) ?></a>
        <i>vs</i>
        <a href="<?= BASE_URL ?>profile.php?u=<?= urlencode($o['comm_name']) ?>"><span class="ao-av alt"><?= e(strtoupper(mb_substr($o['comm_name'], 0, 1))) ?></span><?= e($o['comm_name']) ?> <small><?= e($o['comm_tier']) ?></small></a>
      </div>
    </div>
    <div class="ao-price"><small>In escrow</small><b><?= money($o['price']) ?></b><small>Order #<?= $id ?></small></div>
  </section>

  <section class="ad-stats" aria-label="Case facts">
    <div class="ad-stat col<?= $late ? ' warn' : '' ?>"><small>Delivery</small><b><?= $o['delivered_at'] ? ($late ? 'Late' : 'On time') : '–' ?></b>
      <small><?= $o['delivered_at'] ? e($fmt($o['delivered_at'])) . ' · due ' . e($fmt($o['deliver_by'])) : 'Not delivered' ?></small></div>
    <div class="ad-stat col"><small>Preview opened</small><b><?= count($logs) ?>×</b>
      <small><?= $o['preview_started_at'] ? 'First on ' . e($fmt($o['preview_started_at'])) : 'Client never opened it' ?></small></div>
    <div class="ad-stat col"><small>Revisions</small><b><?= count($revs) ?> / <?= MAX_REVISIONS ?></b>
      <small><?= $revs ? 'Requested by the client' : 'None requested' ?></small></div>
    <div class="ad-stat col"><small>Chat messages</small><b><?= count($msgs) ?></b>
      <small>Log is read-only</small></div>
  </section>

  <div class="ad-cols">
    <div class="ad-main ao-main">

      <section class="ad-card ao-reason<?= $auto ? ' auto' : '' ?>">
        <h2><?= $auto ? 'Automatic dispute' : 'Client’s reason' ?></h2>
        <blockquote><?= nl2br(e($o['reason'])) ?></blockquote>
        <?php if ($o['final_name']): ?><p class="ad-note">Delivered file: <b><?= e($o['final_name']) ?></b></p><?php endif; ?>
      </section>

      <?php if ($revs): ?>
      <section class="ad-card">
        <h2>Revision requests</h2>
        <ul class="ad-rowl ao-revs"><?php foreach ($revs as $rv): ?>
          <li><div class="ad-ct"><b>Round <?= (int)$rv['round_no'] ?></b>
            <small>Asked <?= e($fmt($rv['created_at'])) ?> · due <?= e($fmt($rv['due_by'])) ?></small>
            <p><?= nl2br(e($rv['notes'])) ?></p></div>
            <span class="ad-pill <?= $rv['fulfilled_at'] ? 'ok' : 'bad' ?>"><?= $rv['fulfilled_at'] ? 'Delivered ' . e($fmt($rv['fulfilled_at'])) : 'Not delivered' ?></span></li>
        <?php endforeach; ?></ul>
      </section>
      <?php endif; ?>

      <section class="ad-card">
        <h2>Chat log <small>read-only</small></h2>
        <?php if (!$msgs): ?><p class="ad-note" style="border:0">No messages were sent on this order.</p><?php else: ?>
        <ul class="ao-chat" tabindex="0" aria-label="Chat between client and commissioner">
          <?php foreach ($msgs as $m): $fromClient = (int)$m['sender_id'] === (int)$o['client_id']; ?>
            <li class="<?= $fromClient ? 'cl' : 'cm' ?>">
              <div class="ao-b"><?= e($m['body']) ?></div>
              <small><?= e($m['username']) ?> · <?= e($fmt($m['created_at'])) ?></small>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </section>

      <section class="ad-card ao-decide" id="decision">
        <?php if ($isOpen): ?>
          <h2>Your decision</h2>
          <form method="post" action="action.php" onsubmit="return confirm('Final decision. Continue?')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="resolve">
            <input type="hidden" name="order_id" value="<?= $id ?>">
            <div class="ao-opts">
              <label class="ao-opt pay"><input type="radio" name="decision" value="release" required>
                <span><b>Pay the commissioner</b>
                  <em>Work is valid. <?= e($o['comm_name']) ?> receives <?= money($payout) ?> after the <?= $pct ?>% fee (<?= money($fee) ?>).</em>
                  <small>Counts as an abusive dispute for the client.</small></span></label>
              <label class="ao-opt ref"><input type="radio" name="decision" value="refund">
                <span><b>Refund the client</b>
                  <em>Work is not valid. <?= e($o['client_name']) ?> gets back <?= money($o['price']) ?> and the request reopens.</em>
                  <small>Counts as a lost dispute: tier drops and badge is removed.</small></span></label>
            </div>
            <div class="field"><label class="lbl" for="note">Note for both sides</label>
              <textarea id="note" name="note" maxlength="1000" required placeholder="Explain what you checked and why you decided this way."></textarea>
              <p class="hint">Both parties see this note with the outcome. The decision can’t be undone.</p></div>
            <button class="ad-btn pri">Resolve dispute</button>
          </form>
        <?php else: $rel = $o['resolution'] === 'release'; ?>
          <h2>Resolved</h2>
          <div class="ao-done <?= $rel ? 'pay' : 'ref' ?>">
            <b><?= $rel ? 'Paid to the commissioner' : 'Refunded to the client' ?></b>
            <?php if ($o['resolved_at']): ?><small><?= e($fmt($o['resolved_at'])) ?></small><?php endif; ?>
            <p><?= nl2br(e($o['admin_note'])) ?></p>
          </div>
        <?php endif; ?>
      </section>
    </div>

    <aside class="ad-side ao-side">
      <section class="ad-card pad">
        <h2>Timeline</h2>
        <ol class="ao-tl"><?php foreach ($ev as [$t, $label, $tone]): ?>
          <li class="<?= e($tone) ?>"><b><?= e($label) ?></b><small><?= e(date('M j, g:i A', $t)) ?></small></li>
        <?php endforeach; ?></ol>
      </section>

      <section class="ad-card pad">
        <h2>Payout if released</h2>
        <div class="ao-kv"><span>Order price</span><b><?= money($o['price']) ?></b></div>
        <div class="ao-kv"><span>Platform fee (<?= $pct ?>%)</span><b>−<?= money($fee) ?></b></div>
        <div class="ao-kv tot"><span>Commissioner gets</span><b><?= money($payout) ?></b></div>
        <p class="ad-note" style="padding:.7rem 0 0;border:0">Escrow: <?= e(ucfirst($o['escrow_status'])) ?></p>
      </section>

      <?php if ($logs): ?>
      <section class="ad-card pad">
        <h2>Preview views (<?= count($logs) ?>)</h2>
        <ul class="ao-log"><?php foreach ($logs as $l): ?>
          <li><span><?= e(date('M j, g:i:s A', strtotime($l['created_at']))) ?></span><code><?= e($l['ip']) ?></code></li>
        <?php endforeach; ?></ul>
      </section>
      <?php endif; ?>
    </aside>
  </div>
</main>
</body></html>