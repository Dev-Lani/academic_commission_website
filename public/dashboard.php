<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me  = require_login();
$uid = (int)$me['id'];
$isAdmin  = $me['role'] === 'admin';
$isClient = $me['role'] === 'client';

function one($sql, $p = []){ global $pdo; $q = $pdo->prepare($sql); $q->execute($p); return $q->fetchColumn(); }

$live = "('pending_agree','active','delivered','disputed')";
if ($isAdmin) {
  $stats = [[one("SELECT COUNT(*) FROM disputes WHERE status='open'"), 'Open disputes'],
            [one("SELECT COUNT(*) FROM users WHERE role<>'admin' AND is_verified=0"), 'Unverified accounts'],
            [one("SELECT COUNT(*) FROM orders WHERE status IN $live"), 'Live orders'],
            [money(one("SELECT COALESCE(SUM(price),0) FROM orders WHERE escrow_status='held'")), 'In escrow']];
} elseif ($isClient) {
  $stats = [[one("SELECT COUNT(*) FROM posts WHERE user_id=? AND status='open'", [$uid]), 'Open requests'],
            [one("SELECT COUNT(*) FROM offers o JOIN posts p ON p.id=o.post_id WHERE p.user_id=? AND p.status='open'", [$uid]), 'Offers waiting'],
            [one("SELECT COUNT(*) FROM orders WHERE client_id=? AND status IN $live", [$uid]), 'Active orders'],
            [money(one("SELECT COALESCE(SUM(price),0) FROM orders WHERE client_id=? AND escrow_status='held'", [$uid])), 'In escrow']];
} else {
  $stats = [[one("SELECT COUNT(*) FROM offers o JOIN posts p ON p.id=o.post_id WHERE o.user_id=? AND p.status='open'", [$uid]), 'Offers sent'],
            [one("SELECT COUNT(*) FROM orders WHERE commissioner_id=? AND status IN $live", [$uid]), 'Active orders'],
            [money(one("SELECT COALESCE(SUM(price),0) FROM orders WHERE commissioner_id=? AND escrow_status='held'", [$uid])), 'To be released'],
            [money(one("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE user_id=? AND type='release'", [$uid])), 'Earned']];
}
// Which stat tile is highlighted (only when its value is above zero)
$hot   = $isAdmin ? 0 : 1;
$hotOn = (int)$stats[$hot][0] > 0;
$liveN = $isAdmin ? (int)$stats[2][0] : (int)$stats[$isClient ? 2 : 1][0];

/* Active orders */
$q = $pdo->prepare("SELECT ord.id, ord.status, ord.agree_by, ord.deliver_by, ord.review_by, p.title,
    IF(ord.client_id=?, cm.username, cl.username) AS other
  FROM orders ord JOIN posts p ON p.id=ord.post_id
  JOIN users cl ON cl.id=ord.client_id JOIN users cm ON cm.id=ord.commissioner_id
  WHERE (ord.client_id=? OR ord.commissioner_id=?) AND ord.status IN $live
  ORDER BY FIELD(ord.status,'delivered','pending_agree','disputed','active'), ord.deliver_by LIMIT 5");
$q->execute([$uid, $uid, $uid]);
$orders = $q->fetchAll();
$dl = ['delivered' => ['review_by', 'Review: '], 'pending_agree' => ['agree_by', 'Reply: '], 'active' => ['deliver_by', 'Due: ']];

/* Second panel: my open requests (client) or my open offers (commissioner) */
if ($isClient) {
  $q = $pdo->prepare("SELECT p.id, p.title, p.budget_min, p.budget_max, p.deadline, s.name AS subject,
      (SELECT COUNT(*) FROM offers o WHERE o.post_id=p.id) AS n
    FROM posts p JOIN subjects s ON s.id=p.subject_id WHERE p.user_id=? AND p.status='open' ORDER BY p.created_at DESC LIMIT 5");
  $q->execute([$uid]);
} elseif (!$isAdmin) {
  $q = $pdo->prepare("SELECT p.id, p.title, p.deadline, o.price, s.name AS subject
    FROM offers o JOIN posts p ON p.id=o.post_id JOIN subjects s ON s.id=p.subject_id
    WHERE o.user_id=? AND p.status='open' ORDER BY o.created_at DESC LIMIT 5");
  $q->execute([$uid]);
}
$second = $isAdmin ? [] : $q->fetchAll();

/* Needs attention. Each item: title, detail, href, btn, prim (solid button), red, ts + pre (countdown), sec */
$att = [];
$now = time();
if ($isAdmin) {
  $n = (int)$stats[0][0];
  if ($n) $att[] = ['title' => plural($n, 'dispute', 'disputes') . ' to review', 'detail' => 'Payment stays in escrow until you decide',
                    'href' => 'admin/', 'btn' => 'Review', 'prim' => true, 'red' => true];
} else {
  foreach ($orders as $o) { // deadlines first
    $st = $o['status'];
    if (!$isClient && $st === 'pending_agree')
      $att[] = ['title' => 'Confirm order: ' . $o['title'], 'ts' => $o['agree_by'], 'pre' => 'Reply: ', 'sec' => true,
                'href' => 'order.php?id=' . $o['id'], 'btn' => 'Confirm order', 'prim' => true, 'red' => strtotime($o['agree_by']) - $now < 86400];
    elseif ($isClient && $st === 'delivered')
      $att[] = ['title' => 'Review work: ' . $o['title'], 'ts' => $o['review_by'], 'pre' => 'Review: ',
                'href' => 'order.php?id=' . $o['id'], 'btn' => 'Review work', 'prim' => true, 'red' => strtotime($o['review_by']) - $now < 86400];
    elseif (!$isClient && $st === 'active' && strtotime($o['deliver_by']) - $now < 86400)
      $att[] = ['title' => 'Due soon: ' . $o['title'], 'ts' => $o['deliver_by'], 'pre' => 'Due: ',
                'href' => 'order.php?id=' . $o['id'], 'btn' => 'Deliver', 'prim' => true, 'red' => true];
  }
  if ($oid = unrated_order($uid))
    $att[] = ['title' => 'Rate your last order', 'detail' => 'Required before you can post or send offers',
              'href' => 'order.php?id=' . $oid, 'btn' => 'Rate', 'prim' => false, 'red' => false];
  if ($m = messages_unread($uid))
    $att[] = ['title' => plural($m, 'unread message', 'unread messages'), 'detail' => 'In your order chats',
              'href' => 'messages.php', 'btn' => 'Open', 'prim' => false, 'red' => false];
  $labels = ['offer' => 'New offers on', 'agreed' => 'Order started:', 'declined' => 'Order declined:',
             'expired' => 'No response on', 'approved' => 'Work approved:',
             'disputed' => 'Dispute opened on', 'released' => 'Payment released:', 'resolved' => 'Dispute resolved:'];
  $q = $pdo->prepare("SELECT n.type, n.order_id, p.id AS pid, p.title FROM notifications n JOIN posts p ON p.id=n.post_id
    WHERE n.user_id=? AND n.is_read=0 AND n.type NOT IN ('accepted','delivered') ORDER BY n.updated_at DESC LIMIT 4");
  $q->execute([$uid]);
  foreach ($q->fetchAll() as $r) {
    $toPost = in_array($r['type'], ['offer', 'declined', 'expired'], true) || !$r['order_id'];
    $att[] = ['title' => ($labels[$r['type']] ?? 'Update on') . ' “' . $r['title'] . '”', 'detail' => '',
              'href' => $toPost ? 'post.php?id=' . $r['pid'] : 'order.php?id=' . $r['order_id'],
              'btn' => $toPost ? 'View offers' : 'Open order', 'prim' => false, 'red' => false];
  }
}
$att = array_slice($att, 0, 4);

$s = $isAdmin ? null : user_stats($uid);

// Segmented progress bar for an order status
function d_seg($status){
  $n = ['pending_agree' => 1, 'active' => 2, 'delivered' => 3, 'disputed' => 3, 'completed' => 4][$status] ?? 0;
  $lab = ['pending_agree' => 'Accepted', 'active' => 'Started', 'delivered' => 'Delivered', 'disputed' => 'Delivered, under dispute', 'completed' => 'Completed'][$status] ?? '';
  $h = '<div class="d-seg" role="img" aria-label="Step ' . $n . ' of 4: ' . e($lab) . '">';
  for ($i = 1; $i <= 4; $i++) {
    $c = $i <= $n ? ' on' : '';
    if ($status === 'disputed' && $i === 3) $c = ' bad';
    $h .= '<i class="' . trim($c) . '"></i>';
  }
  return $h . '</div>';
}
?>
<!doctype html>
<html lang="en"><head><title>Dashboard · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/dash.css"></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">
  <div class="d-head">
    <div><h1>Hi, <?= e($me['username']) ?></h1>
      <small><?= $isAdmin ? 'Admin' : ($isClient ? 'Client' : 'Commissioner') ?> · <?= e(plural($liveN, 'live order', 'live orders')) ?></small></div>
    <?php if ($isClient): ?><a class="btn" href="create_post.php">New request</a>
    <?php elseif ($isAdmin): ?><a class="btn" href="admin/">Admin panel</a>
    <?php else: ?><a class="btn" href="forum.php?s=all">Browse requests</a><?php endif; ?>
  </div>

  <div class="d-stats">
    <?php foreach ($stats as $i => [$val, $label]): ?>
      <div class="d-stat<?= $hotOn && $i === $hot ? ' hot' : '' ?>"><small><?= e($label) ?></small><b><?= e($val) ?></b></div>
    <?php endforeach; ?>
  </div>

  <div class="d-cols">
    <div class="d-main">

      <section aria-labelledby="h-att">
        <div class="d-sh"><h2 id="h-att">Needs your attention</h2><a href="inbox.php">Notifications</a></div>
        <?php if (!$att): ?><p class="d-empty">You're all caught up.</p><?php else: ?>
        <ul class="d-list">
          <?php foreach ($att as $a): ?>
          <li class="d-att<?= !empty($a['red']) ? ' red' : '' ?>">
            <span class="d-dot" aria-hidden="true"></span>
            <div class="d-at"><b><?= e($a['title']) ?></b>
              <?php if (!empty($a['ts'])): ?>
                <small><span data-deadline="<?= e(date('c', strtotime($a['ts']))) ?>" data-prefix="<?= e($a['pre']) ?>"<?= !empty($a['sec']) ? ' data-sec="1"' : '' ?>><?= e($a['pre'] . date('M j, g:i A', strtotime($a['ts']))) ?></span></small>
              <?php elseif (!empty($a['detail'])): ?><small><?= e($a['detail']) ?></small><?php endif; ?>
            </div>
            <a class="btn<?= $a['prim'] ? '' : ' ghost' ?>" href="<?= e($a['href']) ?>"><?= e($a['btn']) ?></a>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </section>

      <section aria-labelledby="h-orders">
        <div class="d-sh"><h2 id="h-orders">Active orders</h2><a href="orders.php">All orders</a></div>
        <?php if (!$orders): ?><p class="d-empty">No live orders.</p><?php else: ?>
        <div class="d-list">
          <?php foreach ($orders as $o):
            $st = $o['status'];
            $cta = ['Open', true];
            if     (!$isClient && $st === 'pending_agree') $cta = ['Confirm order', false];
            elseif (!$isClient && $st === 'active')        $cta = ['Deliver', false];
            elseif ($isClient && $st === 'delivered')      $cta = ['Review work', false];
          ?>
          <article class="d-card">
            <div class="d-top">
              <span class="d-av" aria-hidden="true"><?= e(strtoupper(mb_substr($o['other'], 0, 1))) ?></span>
              <div class="d-who"><a class="d-t" href="order.php?id=<?= (int)$o['id'] ?>"><?= e($o['title']) ?></a>
                <small><?= $isClient ? 'With ' : 'For ' ?><?= e($o['other']) ?></small></div>
              <?= status_tag($st) ?>
            </div>
            <?= d_seg($st) ?>
            <div class="d-row2">
              <?php if (isset($dl[$st])): [$col, $pre] = $dl[$st]; $ts = strtotime($o[$col]); ?>
                <span class="d-ck<?= $ts - $now < 86400 ? ' red' : '' ?>"><span data-deadline="<?= e(date('c', $ts)) ?>" data-prefix="<?= e($pre) ?>"<?= $st === 'pending_agree' ? ' data-sec="1"' : '' ?>><?= e($pre . date('M j, g:i A', $ts)) ?></span></span>
              <?php else: ?><span class="d-ck">Admin review</span><?php endif; ?>
              <a class="btn<?= $cta[1] ? ' ghost' : '' ?>" href="order.php?id=<?= (int)$o['id'] ?>"><?= e($cta[0]) ?></a>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </section>

      <?php if (!$isAdmin): ?>
      <section aria-labelledby="h-second">
        <div class="d-sh"><h2 id="h-second"><?= $isClient ? 'Your open requests' : 'Your open offers' ?></h2><a href="forum.php?s=all">Forum</a></div>
        <?php if (!$second): ?><p class="d-empty"><?= $isClient ? 'No open requests. Post one to get offers.' : 'No open offers. Browse the forum to find work.' ?></p><?php else: ?>
        <div class="d-list">
          <?php foreach ($second as $r): ?>
          <div class="d-rq">
            <a class="d-t" href="post.php?id=<?= (int)$r['id'] ?>"><?= e($r['title']) ?>
              <small><?= e($r['subject']) ?><?= $isClient ? ' · ' . e(money($r['budget_min'])) . ' – ' . e(money($r['budget_max'])) : '' ?></small></a>
            <?php if ($isClient): ?><span class="pill<?= $r['n'] ? ' ok' : '' ?>"><?= $r['n'] ? e(plural((int)$r['n'], 'offer', 'offers')) : 'No offers yet' ?></span>
            <?php else: ?><span class="pill"><?= e(money($r['price'])) ?></span><?php endif; ?>
            <span class="d-due">Due <?= e(date('M j', strtotime($r['deadline']))) ?></span>
            <a class="btn ghost" href="post.php?id=<?= (int)$r['id'] ?>">View</a>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </section>
      <?php endif; ?>
    </div>

    <aside class="d-aside">
      <?php if ($s): $tr = trust_score($s); ?>
      <section class="d-pc" aria-labelledby="h-prof">
        <h2 id="h-prof">Your reputation</h2>
        <div class="d-rep">
          <div class="d-ring" style="--p:<?= (int)$tr ?>" role="img" aria-label="Trust score <?= (int)$tr ?> out of 100"><b><?= (int)$tr ?></b></div>
          <div><b>Trust score</b><small>out of 100</small><small>Tier: <?= e($me['tier']) ?></small></div>
        </div>
        <div class="d-kv"><span>Rating</span><b><?= $s['rating_n'] ? number_format($s['rating'], 1) . ' (' . $s['rating_n'] . ')' : 'No ratings yet' ?></b></div>
        <div class="d-kv"><span>Completed orders</span><b><?= (int)$s['completed'] ?></b></div>
        <div class="d-chips"><?php foreach (user_badges($s) as $b): ?><span class="chip"><?= e($b) ?></span><?php endforeach; ?></div>
        <a class="d-more" href="profile.php?u=<?= urlencode($me['username']) ?>">View profile</a>
      </section>
      <?php endif; ?>

      <section class="d-pc" aria-labelledby="h-ql">
        <h2 id="h-ql">Quick links</h2>
        <div class="d-ql">
          <?php $um = messages_unread($uid); $un = unread_count($uid); ?>
          <a href="messages.php">Messages<?= $um ? ' <span class="badge d-badge">' . $um . '</span>' : '<span aria-hidden="true">›</span>' ?></a>
          <a href="inbox.php">Notifications<?= $un ? ' <span class="badge d-badge">' . $un . '</span>' : '<span aria-hidden="true">›</span>' ?></a>
          <a href="transactions.php">Transactions <span aria-hidden="true">›</span></a>
          <a href="forum.php?s=all">Browse forum <span aria-hidden="true">›</span></a>
          <?php if ($isAdmin): ?><a href="admin/">Admin panel <span aria-hidden="true">›</span></a><?php endif; ?>
        </div>
      </section>
    </aside>
  </div>
</main>
</body></html>