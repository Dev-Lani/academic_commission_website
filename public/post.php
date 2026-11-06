<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();
$id = (int)($_GET['id'] ?? 0);

$q = $pdo->prepare("SELECT p.*, u.username, u.tier, u.is_verified, s.name AS subject, s.slug, v.value AS my_vote
  FROM posts p
  JOIN users u ON u.id=p.user_id
  JOIN subjects s ON s.id=p.subject_id
  LEFT JOIN votes v ON v.post_id=p.id AND v.user_id=?
  WHERE p.id=?");
$q->execute([$me['id'], $id]);
$p = $q->fetch();
if (!$p) { http_response_code(404); echo 'Request not found. <a href="forum.php">Back to the forum</a>'; exit; }

$mine   = $p['user_id'] == $me['id'];
$isComm = $me['role'] === 'commissioner';

// binuksan ng may-ari ang post = basa na ang notification
if ($mine) $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=? AND post_id=?')->execute([$me['id'], $id]);

$c = $pdo->prepare('SELECT COUNT(*) FROM offers WHERE post_id=?');
$c->execute([$id]);
$offerCount = (int)$c->fetchColumn();

$flashErr = $_SESSION['flash_err'] ?? '';
$old      = $_SESSION['old_offer'] ?? null;
unset($_SESSION['flash_err'], $_SESSION['old_offer']);
$msg = $_GET['msg'] ?? '';
$q = $pdo->prepare("SELECT id FROM orders WHERE post_id=? AND status IN ('pending_agree','active','delivered','disputed','completed') AND (client_id=? OR commissioner_id=?) LIMIT 1");
$q->execute([$id, $me['id'], $me['id']]);
$myOrder = $q->fetch();

$tiers = ['Bronze', 'Silver', 'Gold', 'Platinum'];
$tf    = in_array($_GET['tier'] ?? '', $tiers, true) ? $_GET['tier'] : '';
$sort  = (($_GET['sort'] ?? 'high') === 'low') ? 'low' : 'high';

$offers = []; $myOffer = null;
if ($mine) {
    $sql = "SELECT o.*, u.username, u.tier, u.is_verified, ord.status AS order_status FROM offers o JOIN users u ON u.id=o.user_id LEFT JOIN orders ord ON ord.offer_id=o.id
          WHERE o.post_id=?" . ($tf ? " AND u.tier=?" : "") .
         " ORDER BY o.price " . ($sort === 'low' ? 'ASC' : 'DESC') .
         ", FIELD(u.tier,'Platinum','Gold','Silver','Bronze'), o.created_at ASC";
  $params = [$id]; if ($tf) $params[] = $tf;
  $q = $pdo->prepare($sql);
  $q->execute($params);
  $offers = $q->fetchAll();
} elseif ($isComm) {
  $q = $pdo->prepare('SELECT * FROM offers WHERE post_id=? AND user_id=?');
  $q->execute([$id, $me['id']]);
  $myOffer = $q->fetch();
}
$f  = $old ?? ($myOffer ?: ['price'=>'','deliver_by'=>'','message'=>'','sample'=>'']);
$dv = $f['deliver_by'] ? date('Y-m-d\TH:i', strtotime($f['deliver_by'])) : '';
?>
<!doctype html>
<html lang="en"><head><title><?= e($p['title']) ?> · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">
  <div class="page-head">
    <p><a href="forum.php?s=<?= e($p['slug']) ?>"><?= e($p['subject']) ?></a></p>
    <h1><?= e($p['title']) ?></h1>
    <p><?= e(plural($offerCount, 'offer', 'offers')) ?> so far</p>
  </div>

  <?php if ($msg === 'sent'): ?><div class="alert ok">Offer sent. Only the client can see it.</div><?php endif; ?>
  <?php if ($msg === 'withdrawn'): ?><div class="alert ok">Offer withdrawn.</div><?php endif; ?>
    <?php if ($myOrder): ?><div class="alert ok">This request has an order. <a href="order.php?id=<?= (int)$myOrder['id'] ?>">View order</a></div><?php endif; ?>

  <div class="layout">
    <div>
      <section class="panel post-full">
        <?= vote_box($p, $me, here()) ?>
        <div class="desc"><?= nl2br(e($p['description'])) ?></div>
      </section>

      <?php if ($mine): ?>
      <section class="panel" style="margin-top:1.5rem">
        <h2>Offers (<?= count($offers) ?>)</h2>
        <form class="filters" method="get">
          <input type="hidden" name="id" value="<?= $id ?>">
          <label>Sort
            <select name="sort" onchange="this.form.submit()">
              <option value="high" <?= $sort==='high'?'selected':'' ?>>Highest bid first</option>
              <option value="low"  <?= $sort==='low'?'selected':'' ?>>Lowest bid first</option>
            </select></label>
          <label>Tier
            <select name="tier" onchange="this.form.submit()">
              <option value="">All tiers</option>
              <?php foreach ($tiers as $t): ?><option <?= $tf===$t?'selected':'' ?>><?= e($t) ?></option><?php endforeach; ?>
            </select></label>
        </form>
        <?php if (!$offers): ?>
          <p class="empty"><?= $tf ? 'No offers from this tier.' : 'No offers yet. Offers from commissioners show up here, visible only to you.' ?></p>
        <?php endif; ?>
        <?php foreach ($offers as $o): ?>
          <article class="offer">
            <div>
              <div class="offer-who">
                <?php $st = user_stats($o['user_id']); ?>
                <b><a href="profile.php?u=<?= urlencode($o['username']) ?>"><?= e($o['username']) ?></a></b>
                <span class="chip tier"><?= e($o['tier']) ?></span>
                <?php if ($o['is_verified']): ?><span class="chip">Verified</span><?php endif; ?>
              </div>
              <p class="offer-msg"><?= nl2br(e($o['message'])) ?></p>
              <?php if ($o['sample']): ?><p class="offer-sample"><b>Sample work:</b> <?= sample_html($o['sample']) ?></p><?php endif; ?>
              <small class="muted"><?= $st['rating_n'] ? number_format($st['rating'], 1) . ' ★ (' . $st['rating_n'] . ') · ' : 'No ratings yet · ' ?><?= $st['completed'] ?> done · Can deliver by <?= e(date('M j, g:i A', strtotime($o['deliver_by']))) ?></small>
            </div>
            <div class="offer-side">
              <div class="price"><?= money($o['price']) ?></div>
              <?php if ($p['status'] === 'open' && !$o['order_status']): ?>
                <form method="post" action="order_action.php" onsubmit="return confirm('Accept this offer?')">
                  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="accept">
                  <input type="hidden" name="offer_id" value="<?= (int)$o['id'] ?>">
                  <button class="btn sm">Accept</button>
                </form>
              <?php elseif ($o['order_status']): echo status_tag($o['order_status']); endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </section>

      <?php elseif ($isComm && $p['status'] === 'open'): ?>
      <section class="panel form-panel" style="margin-top:1.5rem;max-width:none">
        <h2 style="padding:0;border:0"><?= $myOffer ? 'Your offer' : 'Send an offer' ?></h2>
        <p class="muted">The client's budget is <?= money($p['budget_min']) ?> to <?= money($p['budget_max']) ?>. Only the client can see your offer.</p>
        <?php if ($flashErr): ?><div class="alert err" role="alert"><?= e($flashErr) ?></div><?php endif; ?>
        <form method="post" action="offer.php">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="post_id" value="<?= $id ?>">
          <div class="form-grid">
            <div class="field"><label class="lbl" for="price">Your price (₱)</label>
              <input type="number" id="price" name="price" min="1" value="<?= e($f['price']) ?>" required></div>
            <div class="field"><label class="lbl" for="deliver_by">Deliver by</label>
              <input type="datetime-local" id="deliver_by" name="deliver_by" min="<?= date('Y-m-d\TH:i') ?>"
                     max="<?= e(date('Y-m-d\TH:i', strtotime($p['deadline']))) ?>" value="<?= e($dv) ?>" required></div>
          </div>
          <div class="field"><label class="lbl" for="message">Message to the client</label>
            <textarea id="message" name="message" maxlength="1000" required><?= e($f['message']) ?></textarea>
            <p class="hint">Explain how you will do the work. No ads or contact details.</p></div>
          <div class="field"><label class="lbl" for="sample">Sample work (optional)</label>
            <textarea id="sample" name="sample" maxlength="500" style="min-height:4.5rem"><?= e($f['sample']) ?></textarea>
            <p class="hint">Paste a link or a short excerpt of past work.</p></div>
          <button class="btn"><?= $myOffer ? 'Update offer' : 'Send offer' ?></button>
        </form>
        <?php if ($myOffer): ?>
          <form method="post" action="offer.php" style="margin-top:.75rem"
                onsubmit="return confirm('Withdraw your offer?')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="post_id" value="<?= $id ?>">
            <input type="hidden" name="action" value="withdraw">
            <button class="btn ghost">Withdraw offer</button>
          </form>
        <?php endif; ?>
      </section>
      <?php endif; ?>
    </div>

    <aside class="panel">
      <h2>Request details</h2>
      <div class="profile"><dl>
        <div><dt>Status</dt><dd><?= e(ucfirst(str_replace('_', ' ', $p['status']))) ?></dd></div>
        <div><dt>Type of work</dt><dd><?= e($p['type']) ?></dd></div>
        <div><dt>Pages</dt><dd><?= (int)$p['pages'] ?></dd></div>
        <div><dt>Budget</dt><dd><?= money($p['budget_min']) ?> to <?= money($p['budget_max']) ?></dd></div>
        <div><dt>Deadline</dt><dd><?= e(date('M j, Y g:i A', strtotime($p['deadline']))) ?><br>
          <span class="<?= is_urgent($p) ? 'tag urgent' : 'tag' ?>" data-deadline="<?= e(date('c', strtotime($p['deadline']))) ?>">Deadline</span></dd></div>
        <div><dt>Posted by</dt><dd><?= e($p['username']) ?>
          <?= $p['is_verified'] ? '<span class="chip">Verified</span>' : '' ?><br>
          <small class="muted">Tier: <?= e($p['tier']) ?></small></dd></div>
      </dl></div>
    </aside>
  </div>
</main>
</body></html>