<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();

$q = $pdo->prepare("SELECT n.type, n.is_read, n.order_id, p.id AS pid, p.title,
    (SELECT COUNT(*) FROM offers o WHERE o.post_id=p.id) AS cnt,
    (SELECT MAX(price) FROM offers o WHERE o.post_id=p.id) AS top,
    ord.agree_by, cl.username AS client_name, cm.username AS comm_name
  FROM notifications n
  JOIN posts p ON p.id=n.post_id
  LEFT JOIN orders ord ON ord.id=n.order_id
  LEFT JOIN users cl ON cl.id=ord.client_id
  LEFT JOIN users cm ON cm.id=ord.commissioner_id
  WHERE n.user_id=?
  ORDER BY n.is_read ASC, n.updated_at DESC LIMIT 50");
$q->execute([$me['id']]);

// [headline, detail, link, button]
function note_view($r){
  $post  = 'post.php?id=' . $r['pid'];
  switch ($r['type']) {
    case 'offer':
      if (!$r['cnt']) return null;
      return [plural((int)$r['cnt'], 'commissioner has', 'commissioners have') . ' left an offer',
              'On “' . $r['title'] . '”. Highest bid ' . money($r['top']) . '.', $post, 'View offers'];
    case 'accepted':
      return [$r['client_name'] . ' accepted your offer',
              'On “' . $r['title'] . '”. Confirm before ' . date('g:i A', strtotime($r['agree_by'])) . '.', 'order.php?id=' . $r['order_id'], 'Review order'];
    case 'agreed':
      return [$r['comm_name'] . ' agreed to your order', '“' . $r['title'] . '” has started.', 'order.php?id=' . $r['order_id'], 'Open order'];
    case 'declined':
      return [$r['comm_name'] . ' declined your order', '“' . $r['title'] . '” is open again. Pick another offer.', $post, 'View offers'];
    case 'expired':
      return [$r['comm_name'] . ' did not respond in time', '“' . $r['title'] . '” is open again. Pick another offer.', $post, 'View offers'];
    case 'delivered':
      return [$r['comm_name'] . ' delivered the work', '“' . $r['title'] . '” is ready. Open the preview and approve or dispute before the review window ends.', 'order.php?id=' . $r['order_id'], 'Review work'];
    case 'approved':
      return [$r['client_name'] . ' approved your work', '“' . $r['title'] . '” is complete and payment was released.', 'order.php?id=' . $r['order_id'], 'Open order'];
    case 'disputed':
      return [$r['client_name'] . ' opened a dispute', 'On “' . $r['title'] . '”. An admin will review it. Payment stays in escrow.', 'order.php?id=' . $r['order_id'], 'Open order'];
    case 'resolved':
      return ['An admin resolved the dispute', 'On “' . $r['title'] . '”. Open the order to see the decision.', 'order.php?id=' . $r['order_id'], 'Open order'];
    case 'released':
      return ['Payment released automatically', 'The review window for “' . $r['title'] . '” ended with no response.', 'order.php?id=' . $r['order_id'], 'Open order'];
  }
  return null;
}
$items = array_filter(array_map(fn($r) => ($v = note_view($r)) ? [$r['is_read'], $v] : null, $q->fetchAll()));
?>
<!doctype html>
<html lang="en"><head><title>Notifications · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">
  <div class="page-head"><h1>Notifications</h1><p>Offers and order updates. Only you can see these. Chat messages are in <a href="messages.php">Messages</a>.</p></div>
  <section class="panel">
    <?php if (!$items): ?>
      <p class="empty">Nothing here yet.</p>
    <?php else: ?>
    <ul class="rows">
      <?php foreach ($items as [$read, $v]): ?>
        <li class="<?= $read ? '' : 'unread' ?>">
          <div><b><?= e($v[0]) ?></b><small><?= e($v[1]) ?></small></div>
          <a class="btn ghost" href="<?= e($v[2]) ?>"><?= e($v[3]) ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
</main>
</body></html>