<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me       = require_login();
$uid      = (int)$me['id'];
$isClient = $me['role'] !== 'commissioner';

const ORD_PER_PAGE = 10;

$tabs  = ['all' => 'All', 'action' => 'Needs action', 'progress' => 'In progress', 'delivered' => 'Delivered',
          'disputed' => 'Disputed', 'completed' => 'Completed', 'closed' => 'Closed'];
$sorts = ['new' => 'Newest', 'due' => 'Deadline soonest', 'price' => 'Price high to low'];
$g = fn($k) => is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : '';

$tab   = array_key_exists($g('tab'), $tabs) ? $g('tab') : 'all';
$sort  = array_key_exists($g('sort'), $sorts) ? $g('sort') : 'new';
$qtext = mb_substr($g('q'), 0, 80);
$soon  = date('Y-m-d H:i:s', time() + 86400); // 24 hours from now

/* "Needs your action" as SQL. $s makes every placeholder name unique per query. */
function ord_act($isClient, $s, $uid, $soon){
  $rate = "(ord.status='completed' AND NOT EXISTS (SELECT 1 FROM ratings rr WHERE rr.order_id=ord.id AND rr.rater_id=:m$s))";
  if ($isClient) return ["(ord.status='delivered' OR $rate)", [":m$s" => $uid]];
  return ["(ord.status='pending_agree' OR (ord.status='active' AND ord.deliver_by <= :s$s) OR $rate)",
          [":m$s" => $uid, ":s$s" => $soon]];
}

/* WHERE clause + params for the current tab and search */
$build = function ($s) use ($uid, $tab, $qtext, $isClient, $soon) {
  $w = ["(ord.client_id=:w1$s OR ord.commissioner_id=:w2$s)"];
  $p = [":w1$s" => $uid, ":w2$s" => $uid];
  if ($qtext !== '') {
    $like = '%' . addcslashes($qtext, '%_\\') . '%';
    $w[] = "(p.title LIKE :q1$s OR IF(ord.client_id=:o$s, cm.username, cl.username) LIKE :q2$s)";
    $p += [":q1$s" => $like, ":q2$s" => $like, ":o$s" => $uid];
  }
  if ($tab === 'action')         { [$a, $ap] = ord_act($isClient, $s, $uid, $soon); $w[] = $a; $p += $ap; }
  elseif ($tab === 'progress')   $w[] = "ord.status IN ('pending_agree','active')";
  elseif ($tab === 'delivered')  $w[] = "ord.status='delivered'";
  elseif ($tab === 'disputed')   $w[] = "ord.status='disputed'";
  elseif ($tab === 'completed')  $w[] = "ord.status='completed'";
  elseif ($tab === 'closed')     $w[] = "ord.status IN ('declined','expired','cancelled')";
  return [implode(' AND ', $w), $p];
};

/* ---------- one COUNT query for the sidebar ---------- */
[$actD, $actDP] = ord_act($isClient, 'd', $uid, $soon);
$c = $pdo->prepare("SELECT COUNT(*) AS n_all,
    COALESCE(SUM($actD),0) AS n_action,
    COALESCE(SUM(ord.status IN ('pending_agree','active')),0) AS n_progress,
    COALESCE(SUM(ord.status='delivered'),0) AS n_delivered,
    COALESCE(SUM(ord.status='disputed'),0) AS n_disputed,
    COALESCE(SUM(ord.status='completed'),0) AS n_completed,
    COALESCE(SUM(ord.status IN ('declined','expired','cancelled')),0) AS n_closed
  FROM orders ord WHERE (ord.client_id=:w1d OR ord.commissioner_id=:w2d)");
$c->execute($actDP + [':w1d' => $uid, ':w2d' => $uid]);
$cnt = $c->fetch();
$tabCount = ['all' => (int)$cnt['n_all'], 'action' => (int)$cnt['n_action'], 'progress' => (int)$cnt['n_progress'],
             'delivered' => (int)$cnt['n_delivered'], 'disputed' => (int)$cnt['n_disputed'],
             'completed' => (int)$cnt['n_completed'], 'closed' => (int)$cnt['n_closed']];

/* ---------- total for pagination ---------- */
[$wc, $pc] = $build('c');
$t = $pdo->prepare("SELECT COUNT(*) FROM orders ord
  JOIN posts p ON p.id=ord.post_id JOIN users cl ON cl.id=ord.client_id JOIN users cm ON cm.id=ord.commissioner_id
  WHERE $wc");
$t->execute($pc);
$total = (int)$t->fetchColumn();
$pages = max(1, (int)ceil($total / ORD_PER_PAGE));
$page  = min(max(1, (int)$g('page')), $pages);
$off   = ($page - 1) * ORD_PER_PAGE;

/* ---------- the list: one query, unread and rated included ---------- */
[$actA, $actAP] = ord_act($isClient, 'a', $uid, $soon);
[$wb, $pb] = $build('b');
$order = ['new' => 'ord.accepted_at DESC, ord.id DESC', 'due' => 'ord.deliver_by ASC, ord.id DESC',
          'price' => 'ord.price DESC, ord.id DESC'][$sort];
$q = $pdo->prepare("SELECT ord.id, ord.status, ord.price, ord.escrow_status, ord.fee, ord.deliver_by, ord.agree_by, ord.review_by,
    ord.client_id, ord.commissioner_id, p.title,
    IF(ord.client_id=:oa, cm.username, cl.username) AS other_name,
    ($actA) AS needs_action,
    EXISTS (SELECT 1 FROM ratings r2 WHERE r2.order_id=ord.id AND r2.rater_id=:ra) AS rated,
    (SELECT COUNT(*) FROM order_messages m WHERE m.order_id=ord.id AND m.sender_id<>:ua1
       AND m.id > COALESCE((SELECT r.last_id FROM chat_reads r WHERE r.order_id=ord.id AND r.user_id=:ua2), 0)) AS unread
  FROM orders ord
  JOIN posts p ON p.id=ord.post_id
  JOIN users cl ON cl.id=ord.client_id
  JOIN users cm ON cm.id=ord.commissioner_id
  WHERE $wb
  ORDER BY needs_action DESC, $order
  LIMIT " . ORD_PER_PAGE . " OFFSET $off");
$q->execute($actAP + $pb + [':oa' => $uid, ':ra' => $uid, ':ua1' => $uid, ':ua2' => $uid]);
$orders = $q->fetchAll();

/* ---------- helpers ---------- */
$url = function (array $o = []) use ($tab, $qtext, $sort) {
  $a = array_merge(['tab' => $tab === 'all' ? '' : $tab, 'q' => $qtext, 'sort' => $sort === 'new' ? '' : $sort, 'page' => ''], $o);
  $a = array_filter($a, fn($v) => $v !== '' && $v !== null);
  $qs = http_build_query($a, '', '&', PHP_QUERY_RFC3986);
  return 'orders.php' . ($qs !== '' ? '?' . $qs : '');
};
$filtered = $qtext !== '' || $tab !== 'all';

function ord_steps($status){
  $map = ['pending_agree' => ['cur', '', '', ''], 'active' => ['done', 'cur', '', ''],
          'delivered' => ['done', 'done', 'cur', ''], 'disputed' => ['done', 'done', 'bad', ''],
          'completed' => ['done', 'done', 'done', 'done']];
  if (!isset($map[$status])) return '';
  $note = ['cur' => ' <span class="o-sr">(current step)</span>', 'bad' => ' <span class="o-sr">(disputed)</span>',
           'done' => ' <span class="o-sr">(done)</span>', '' => ''];
  $h = '<ol class="o-steps" aria-label="Order progress">';
  foreach (['Accepted', 'Started', 'Delivered', 'Completed'] as $i => $label) {
    $s = $map[$status][$i];
    $h .= '<li' . ($s ? ' class="' . $s . '"' : '') . '>' . e($label) . $note[$s] . '</li>';
  }
  return $h . '</ol>';
}

$onPage = [true => 0, false => 0];
foreach ($orders as $o) $onPage[(int)$o['needs_action'] === 1]++;
$escrowText = ['none' => 'Escrow not funded yet', 'held' => 'Escrow held', 'released' => 'Escrow released', 'refunded' => 'Escrow refunded'];
?>
<!doctype html>
<html lang="en"><head><title>My orders · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/orders.css"></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">
  <div class="page-head"><h1>My orders</h1><p>Accepted offers, from confirmation to delivery.</p></div>

  <div class="o-lay">
    <nav class="o-side" aria-label="Order status">
      <?php foreach (['action', 'all', '-', 'progress', 'delivered', 'disputed', 'completed', 'closed'] as $k):
        if ($k === '-') { echo '<hr>'; continue; }
        $on  = $tab === $k;
        $hot = $k === 'action' && $tabCount['action'] > 0; ?>
        <a href="<?= e($url(['tab' => $k === 'all' ? '' : $k, 'page' => ''])) ?>" class="<?= $on ? 'on' : '' ?><?= $hot ? ' hot' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>>
          <?= e($tabs[$k]) ?> <i><?= (int)$tabCount[$k] ?></i></a>
      <?php endforeach; ?>
    </nav>

    <div class="o-main">
      <form class="o-bar" method="get" action="orders.php" role="search">
        <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= e($tab) ?>"><?php endif; ?>
        <input type="text" name="q" value="<?= e($qtext) ?>" maxlength="80" placeholder="Search title or name" aria-label="Search orders">
        <select name="sort" aria-label="Sort orders" onchange="this.form.submit()">
          <?php foreach ($sorts as $k => $label): ?><option value="<?= e($k) ?>"<?= $sort === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <button class="btn sm">Search</button>
      </form>

      <?php if (!$orders): ?>
        <section class="panel o-empty">
          <?php if ($tabCount['all'] === 0): ?>
            <h2>No orders yet</h2>
            <?php if ($isClient): ?>
              <p>When you accept an offer on one of your requests, the order shows up here.</p>
              <a class="btn" href="create_post.php">Post a request</a>
            <?php else: ?>
              <p>Orders appear here when a client accepts your offer.</p>
              <a class="btn" href="forum.php?s=all">Browse requests</a>
            <?php endif; ?>
          <?php else: ?>
            <h2>No orders match</h2>
            <p>Try a different search or status.</p>
            <a class="btn ghost" href="<?= e($url(['tab' => '', 'q' => '', 'sort' => '', 'page' => ''])) ?>">Clear filters</a>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <div class="o-list">
      <?php $prevAct = null;
      foreach ($orders as $o):
        $status  = $o['status'];
        $isComm  = (int)$o['commissioner_id'] === $uid;
        $other   = $o['other_name'];
        $act     = (int)$o['needs_action'] === 1;
        $closed  = in_array($status, ['declined', 'expired', 'cancelled'], true);
        $needRate = $status === 'completed' && !(int)$o['rated'];
        $price   = (int)$o['price'];
        $unread  = (int)$o['unread'];

        // escrow + what the commissioner receives
        $esc = ''; $pay = '';
        if (!$closed) {
          $esc = $escrowText[$o['escrow_status']] ?? '';
          if ($isComm && $o['escrow_status'] === 'held') {
            $pct = TIER_FEE[$me['tier']] ?? PLATFORM_FEE_PCT;
            $pay = 'You get ' . money($price - (int)round($price * $pct / 100)) . ' when released';
          } elseif ($isComm && $o['escrow_status'] === 'released') {
            $pay = 'You received ' . money($price - (int)$o['fee']);
          }
        }

        // label above the card title
        $flag = '';
        if ($act) {
          if     ($status === 'pending_agree') $flag = 'Reply needed';
          elseif ($status === 'active')        $flag = 'Due soon';
          elseif ($status === 'delivered')     $flag = 'Review needed';
          else                                 $flag = 'Rating required';
        }

        // countdown or note
        $cd = null; $note = null;
        if ($status === 'pending_agree')   $cd = ['Reply: ',  $o['agree_by'],   true,  $isComm ? 'Confirm before it expires' : 'Waiting for the commissioner'];
        elseif ($status === 'active')      $cd = ['Due: ',    $o['deliver_by'], false, 'Deliver by ' . date('M j, g:i A', strtotime($o['deliver_by']))];
        elseif ($status === 'delivered')   $cd = ['Review: ', $o['review_by'],  false, 'Auto-release if no response'];
        elseif ($status === 'disputed')    $note = ['Status', 'Admin review'];
        elseif ($needRate)                 $note = ['Required', 'Rating pending'];

        // main button
        $cta = ['Open', true];
        if     ($isComm && $status === 'pending_agree')  $cta = ['Confirm order', false];
        elseif ($isComm && $status === 'active')         $cta = ['Deliver', false];
        elseif (!$isComm && $status === 'delivered')     $cta = ['Review work', false];
        elseif ($needRate)                               $cta = ['Rate ' . $other, false];

        if ($tab === 'all' && $prevAct !== $act): $prevAct = $act; ?>
          <div class="o-sec"><h2><?= $act ? 'Needs your action' : 'Other orders' ?></h2>
            <span><?= e(plural($onPage[$act], 'order', 'orders')) ?> on this page</span></div>
        <?php endif; ?>

        <article class="o-card<?= $act ? ' act' : '' ?><?= $closed ? ' mute' : '' ?>">
          <?php if ($flag): ?><p class="o-flag"><?= e($flag) ?></p><?php endif; ?>
          <div class="o-top">
            <span class="o-av" aria-hidden="true"><?= e(strtoupper(mb_substr($other, 0, 1))) ?></span>
            <div class="o-who">
              <a class="o-title" href="order.php?id=<?= (int)$o['id'] ?>"><?= e($o['title']) ?></a>
              <small><?= $isComm ? 'For ' : 'With ' ?><?= e($other) ?></small>
            </div>
            <?= status_tag($status) ?>
          </div>

          <?= ord_steps($status) ?>

          <div class="o-mid">
            <div class="o-price"><?= money($price) ?>
              <?php if ($esc): ?><small><?= e($esc) ?></small><?php endif; ?>
              <?php if ($pay): ?><small><?= e($pay) ?></small><?php endif; ?>
            </div>
            <?php if ($cd): $red = strtotime($cd[1]) - time() < 86400; ?>
              <div class="o-clock<?= $red ? ' red' : '' ?>"><small><?= e($cd[3]) ?></small>
                <span data-deadline="<?= e(date('c', strtotime($cd[1]))) ?>" data-prefix="<?= e($cd[0]) ?>"<?= $cd[2] ? ' data-sec="1"' : '' ?>><?= e($cd[0] . date('M j, g:i A', strtotime($cd[1]))) ?></span></div>
            <?php elseif ($note): ?>
              <div class="o-clock"><small><?= e($note[0]) ?></small><?= e($note[1]) ?></div>
            <?php endif; ?>
          </div>

          <div class="o-foot">
            <?php if ($unread): ?>
              <a class="o-msg" href="messages.php?id=<?= (int)$o['id'] ?>"><span class="badge o-badge"><?= $unread ?></span><?= e(plural($unread, 'new message', 'new messages')) ?></a>
            <?php endif; ?>
            <?php if ($isComm && $status === 'pending_agree'): ?>
              <form method="post" action="order_action.php" class="o-inline" onsubmit="return confirm('Decline this order?')">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                <button class="btn ghost" name="action" value="decline">Decline</button>
              </form>
            <?php endif; ?>
            <a class="btn<?= $cta[1] ? ' ghost' : '' ?>" href="order.php?id=<?= (int)$o['id'] ?>"><?= e($cta[0]) ?></a>
          </div>
        </article>
      <?php endforeach; ?>
      </div>

      <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="Pages">
        <?php if ($page > 1): ?><a class="fcl" href="<?= e($url(['page' => $page - 1 > 1 ? $page - 1 : ''])) ?>">‹ Prev</a><?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
          <a class="fcl<?= $i === $page ? ' on' : '' ?>" href="<?= e($url(['page' => $i > 1 ? $i : ''])) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><a class="fcl" href="<?= e($url(['page' => $page + 1])) ?>">Next ›</a><?php endif; ?>
      </nav>
      <?php endif; ?>
    </div>
  </div>
</main>
</body></html>