<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();

$filters = ['all' => 'All', 'unread' => 'Unread', 'offers' => 'Offers', 'orders' => 'Orders', 'money' => 'Payments & disputes'];
$f = is_string($_GET['f'] ?? null) && isset($filters[$_GET['f']]) ? $_GET['f'] : 'all';

$q = $pdo->prepare("SELECT n.type, n.is_read, n.order_id, n.updated_at, p.id AS pid, p.title,
    (SELECT COUNT(*) FROM offers o WHERE o.post_id=p.id) AS cnt,
    (SELECT MAX(price) FROM offers o WHERE o.post_id=p.id) AS top,
    ord.agree_by, cl.username AS client_name, cm.username AS comm_name
  FROM notifications n
  JOIN posts p ON p.id=n.post_id
  LEFT JOIN orders ord ON ord.id=n.order_id
  LEFT JOIN users cl ON cl.id=ord.client_id
  LEFT JOIN users cm ON cm.id=ord.commissioner_id
  WHERE n.user_id=?
  ORDER BY n.updated_at DESC LIMIT 50");
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

// type => [group, icon tone, svg]
$meta = [
  'offer'     => ['offers', 'offer', '<path d="M12 3v18M16 7.5C16 6 14.2 5 12 5S8 6 8 8s1.8 2.5 4 3 4 1 4 3-1.8 3-4 3-4-1-4-2.5"/>'],
  'accepted'  => ['orders', 'good',  '<path d="M5 12.5l4.5 4.5L19 7.5"/>'],
  'agreed'    => ['orders', 'good',  '<path d="M5 12.5l4.5 4.5L19 7.5"/>'],
  'declined'  => ['orders', 'bad',   '<path d="M6 6l12 12M18 6L6 18"/>'],
  'expired'   => ['orders', 'warn',  '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>'],
  'delivered' => ['orders', 'offer', '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/>'],
  'approved'  => ['orders', 'good',  '<path d="M5 12.5l4.5 4.5L19 7.5"/>'],
  'disputed'  => ['money',  'bad',   '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17v.5"/>'],
  'resolved'  => ['money',  'warn',  '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>'],
  'released'  => ['money',  'good',  '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>'],
];

function nt_day($ts){
  $d = date('Y-m-d', strtotime($ts));
  if ($d === date('Y-m-d')) return 'Today';
  if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
  return date('M j, Y', strtotime($ts));
}

$all = [];
foreach ($q->fetchAll() as $r) if ($v = note_view($r)) $all[] = ['r' => $r, 'v' => $v];

$cnt = array_fill_keys(array_keys($filters), 0);
foreach ($all as $a) {
  $cnt['all']++;
  if (!$a['r']['is_read']) $cnt['unread']++;
  $cnt[$meta[$a['r']['type']][0] ?? 'orders']++;
}
$unreadN = $cnt['unread'];

$items = array_filter($all, function ($a) use ($f, $meta) {
  if ($f === 'all') return true;
  if ($f === 'unread') return !$a['r']['is_read'];
  return ($meta[$a['r']['type']][0] ?? 'orders') === $f;
});
$groups = [];
foreach ($items as $a) $groups[nt_day($a['r']['updated_at'])][] = $a;
?>
<!doctype html>
<html lang="en"><head><title>Notifications · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/inbox.css"></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">
  <div class="page-head nt-head">
    <div><h1>Notifications</h1>
      <p>Offers and order updates. Only you can see these. Chat messages are in <a href="messages.php">Messages</a>.</p></div>
    <span class="nt-pill<?= $unreadN ? '' : ' zero' ?>"><?= $unreadN ? '<i>' . (int)$unreadN . '</i> unread' : 'All caught up' ?></span>
  </div>

  <nav class="nt-filters" aria-label="Filter notifications">
    <?php foreach ($filters as $k => $label): ?>
      <a class="fcl<?= $f === $k ? ' on' : '' ?>" href="inbox.php<?= $k === 'all' ? '' : '?f=' . $k ?>"<?= $f === $k ? ' aria-current="page"' : '' ?>>
        <?= e($label) ?> <i><?= (int)$cnt[$k] ?></i></a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$items): ?>
    <section class="panel nt-empty">
      <?php if (!$cnt['all']): ?>
        <h2>Nothing here yet</h2>
        <p>Offers and order updates will show up here.</p>
        <a class="btn" href="<?= $me['role'] === 'client' ? 'create_post.php' : 'forum.php?s=all' ?>"><?= $me['role'] === 'client' ? 'Post a request' : 'Browse requests' ?></a>
      <?php else: ?>
        <h2><?= $f === 'unread' ? 'No unread notifications' : 'Nothing in this filter' ?></h2>
        <p><?= $f === 'unread' ? 'You\'re all caught up.' : 'Try another filter.' ?></p>
        <a class="btn ghost" href="inbox.php">Show all</a>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php foreach ($groups as $day => $list): ?>
    <div class="nt-day"><h2><?= e($day) ?></h2><span><?= e(plural(count($list), 'notification', 'notifications')) ?></span></div>
    <ul class="nt-list">
      <?php foreach ($list as $a): $r = $a['r']; $v = $a['v']; [, $tone, $svg] = $meta[$r['type']] ?? ['', '', '']; ?>
        <li class="nt-row<?= $r['is_read'] ? '' : ' unread' ?>">
          <span class="nt-ic <?= e($tone) ?>" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $svg ?></svg>
          </span>
          <div class="nt-b">
            <b><?= e($v[0]) ?><?= $r['is_read'] ? '' : '<span class="sr"> (unread)</span>' ?></b>
            <small><?= e($v[1]) ?></small>
            <time datetime="<?= e(date('c', strtotime($r['updated_at']))) ?>"><?= e(chat_time($r['updated_at'])) ?></time>
          </div>
          <a class="btn ghost" href="<?= e($v[2]) ?>"><?= e($v[3]) ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endforeach; ?>
</main>
</body></html>