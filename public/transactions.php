<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me  = require_login();
$uid = (int)$me['id'];
$isClient = $me['role'] === 'client';

$filters = ['all' => 'All', 'hold' => 'Escrow', 'release' => 'Payouts', 'refund' => 'Refunds', 'fee' => 'Fees'];
$f = is_string($_GET['type'] ?? null) && isset($filters[$_GET['type']]) ? $_GET['type'] : 'all';

$one = function ($sql) use ($pdo, $uid) {
  $q = $pdo->prepare($sql);
  $q->execute([$uid]);
  return (int)$q->fetchColumn();
};

/* ---------- summary tiles ---------- */
if ($isClient) {
  $stats = [
    ['In escrow now',  money($one("SELECT COALESCE(SUM(price),0) FROM orders WHERE client_id=? AND escrow_status='held'")), 'Waiting for your approval', 'hot'],
    ['Total paid in',  money($one("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE user_id=? AND type='hold'")), 'Into escrow, all orders', ''],
    ['Released',       money($one("SELECT COALESCE(SUM(price),0) FROM orders WHERE client_id=? AND escrow_status='released'")), 'Paid to commissioners', ''],
    ['Refunded',       money($one("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE user_id=? AND type='refund'")), 'Returned to you', 'good'],
  ];
} else {
  $stats = [
    ['Earned',         money($one("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE user_id=? AND type='release'")), 'After platform fees', 'hot'],
    ['To be released', money($one("SELECT COALESCE(SUM(price),0) FROM orders WHERE commissioner_id=? AND escrow_status='held'")), 'Held in escrow', ''],
    ['Fees paid',      money($one("SELECT COALESCE(SUM(fee),0) FROM orders WHERE commissioner_id=? AND escrow_status='released'")), 'Already deducted', ''],
    ['Paid orders',    (string)$one("SELECT COUNT(*) FROM orders WHERE commissioner_id=? AND escrow_status='released'"), 'Payments received', 'good'],
  ];
}

/* ---------- counts per type (for the chips) ---------- */
$c = $pdo->prepare("SELECT t.type, COUNT(*) AS n FROM transactions t JOIN orders ord ON ord.id=t.order_id
  WHERE ord.client_id=? OR ord.commissioner_id=? GROUP BY t.type");
$c->execute([$uid, $uid]);
$cnt = array_fill_keys(array_keys($filters), 0);
foreach ($c->fetchAll() as $r) { $cnt[$r['type']] = (int)$r['n']; $cnt['all'] += (int)$r['n']; }

/* ---------- list ---------- */
$sql = "SELECT t.type, t.amount, t.created_at, p.title, ord.id AS oid
  FROM transactions t
  JOIN orders ord ON ord.id=t.order_id
  JOIN posts p ON p.id=ord.post_id
  WHERE (ord.client_id=:a OR ord.commissioner_id=:b)" . ($f !== 'all' ? " AND t.type=:t" : "") . "
  ORDER BY t.id DESC LIMIT 100";
$q = $pdo->prepare($sql);
$p = [':a' => $uid, ':b' => $uid];
if ($f !== 'all') $p[':t'] = $f;
$q->execute($p);
$rows = $q->fetchAll();

// [label, sign, tone, caption]
function tx_view($type, $isClient){
  if ($type === 'hold')    return $isClient ? ['Paid into escrow', '−', 'out', 'Held until you approve'] : ['Client funded escrow', '', 'mute', 'Held until approval'];
  if ($type === 'release') return $isClient ? ['Released to commissioner', '', 'mute', 'Work approved'] : ['Payment received', '+', 'in', 'After platform fee'];
  if ($type === 'refund')  return $isClient ? ['Refunded to you', '+', 'in', 'Dispute decided for you'] : ['Escrow refunded to client', '', 'mute', 'Dispute lost'];
  return $isClient ? ['Platform fee', '', 'mute', ''] : ['Platform fee', '', 'mute', 'Already deducted from your payment'];
}

$icons = [
  'hold'    => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
  'release' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
  'refund'  => '<path d="M4 9h11a5 5 0 0 1 0 10H8"/><path d="M8 5L4 9l4 4"/>',
  'fee'     => '<path d="M19 5L5 19"/><circle cx="7.5" cy="7.5" r="2"/><circle cx="16.5" cy="16.5" r="2"/>',
];

function tx_day($ts){
  $d = date('Y-m-d', strtotime($ts));
  if ($d === date('Y-m-d')) return 'Today';
  if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
  return date('M j, Y', strtotime($ts));
}

// group by day
$groups = [];
foreach ($rows as $r) $groups[tx_day($r['created_at'])][] = $r;
?>
<!doctype html>
<html lang="en"><head><title>Transactions · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/transactions.css"></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">
  <div class="page-head"><h1>Transactions</h1><p>Escrow holds, payouts, refunds, and platform fees. Payments are simulated.</p></div>

  <div class="tx-stats">
    <?php foreach ($stats as [$label, $val, $sub, $cls]): ?>
      <div class="tx-stat<?= $cls ? ' ' . $cls : '' ?>"><small><?= e($label) ?></small><b><?= e($val) ?></b><span><?= e($sub) ?></span></div>
    <?php endforeach; ?>
  </div>

  <nav class="tx-filters" aria-label="Filter transactions">
    <?php foreach ($filters as $k => $label): ?>
      <a class="fcl<?= $f === $k ? ' on' : '' ?>" href="transactions.php<?= $k === 'all' ? '' : '?type=' . $k ?>"<?= $f === $k ? ' aria-current="page"' : '' ?>>
        <?= e($label) ?> <i><?= (int)$cnt[$k] ?></i></a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$rows): ?>
    <section class="panel tx-empty">
      <?php if ($cnt['all'] === 0): ?>
        <h2>No transactions yet</h2>
        <p>They show up once a commissioner agrees to an order.</p>
        <a class="btn" href="<?= $isClient ? 'create_post.php' : 'forum.php?s=all' ?>"><?= $isClient ? 'Post a request' : 'Browse requests' ?></a>
      <?php else: ?>
        <h2>Nothing in this filter</h2>
        <p>Try another type.</p>
        <a class="btn ghost" href="transactions.php">Show all</a>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php foreach ($groups as $day => $items): ?>
    <div class="tx-day"><h2><?= e($day) ?></h2><span><?= e(plural(count($items), 'transaction', 'transactions')) ?></span></div>
    <ul class="tx-list">
      <?php foreach ($items as $r): [$label, $sign, $tone, $cap] = tx_view($r['type'], $isClient); ?>
        <li class="tx-row">
          <span class="tx-ic <?= e($r['type']) ?>" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$r['type']] ?? '' ?></svg>
          </span>
          <div class="tx-b">
            <b><?= e($label) ?></b>
            <a href="order.php?id=<?= (int)$r['oid'] ?>"><?= e($r['title']) ?></a>
            <small><?= e(date('g:i A', strtotime($r['created_at']))) ?></small>
          </div>
          <div class="tx-amt <?= e($tone) ?>"><?= e($sign . money($r['amount'])) ?><?php if ($cap): ?><small><?= e($cap) ?></small><?php endif; ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endforeach; ?>
</main>
</body></html>