<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();
$isClient = $me['role'] === 'client';

$q = $pdo->prepare("SELECT t.type, t.amount, t.created_at, p.title, ord.id AS oid
  FROM transactions t
  JOIN orders ord ON ord.id=t.order_id
  JOIN posts p ON p.id=ord.post_id
  WHERE ord.client_id=? OR ord.commissioner_id=?
  ORDER BY t.id DESC LIMIT 100");
$q->execute([$me['id'], $me['id']]);
$rows = $q->fetchAll();

// [label, sign]
function tx_view($type, $isClient){
  if ($type === 'hold')    return $isClient ? ['Paid into escrow', '−'] : ['Client funded escrow', ''];
  if ($type === 'release') return $isClient ? ['Released to commissioner', ''] : ['Payment received', '+'];
  if ($type === 'refund')  return $isClient ? ['Refunded to you', '+'] : ['Escrow refunded to client', ''];
  return [($isClient ? 'Platform fee' : 'Platform fee (already deducted from your payment)'), ''];
}
?>
<!doctype html>
<html lang="en"><head><title>Transactions · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">
  <div class="page-head"><h1>Transactions</h1><p>Escrow holds, releases, and platform fees. Payments are simulated.</p></div>
  <section class="panel">
    <?php if (!$rows): ?>
      <p class="empty">No transactions yet. They appear once a commissioner agrees to an order.</p>
    <?php else: ?>
    <ul class="rows">
      <?php foreach ($rows as $r): [$label, $sign] = tx_view($r['type'], $isClient); ?>
        <li>
          <div><b><?= e($label) ?></b>
            <small><?= e($r['title']) ?> · <?= e(date('M j, g:i A', strtotime($r['created_at']))) ?></small></div>
          <div class="row-end"><b><?= $sign . money($r['amount']) ?></b>
            <a class="btn ghost" href="order.php?id=<?= (int)$r['oid'] ?>">Order</a></div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
</main>
</body></html>