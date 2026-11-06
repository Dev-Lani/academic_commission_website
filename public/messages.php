<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me  = require_login();
$uid = (int)$me['id'];

// Every order that has a chat: in progress, finished, or with any past message.
$q = $pdo->prepare("SELECT ord.id, ord.status, ord.started_at, p.title, oth.username AS other_name,
    (SELECT m.body FROM order_messages m WHERE m.order_id=ord.id ORDER BY m.id DESC LIMIT 1) AS last_body,
    (SELECT m.sender_id FROM order_messages m WHERE m.order_id=ord.id ORDER BY m.id DESC LIMIT 1) AS last_sender,
    (SELECT m.created_at FROM order_messages m WHERE m.order_id=ord.id ORDER BY m.id DESC LIMIT 1) AS last_at,
    (SELECT COUNT(*) FROM order_messages m WHERE m.order_id=ord.id AND m.sender_id<>:u1
       AND m.id > COALESCE((SELECT r.last_id FROM chat_reads r WHERE r.order_id=ord.id AND r.user_id=:u2), 0)) AS unread
  FROM orders ord
  JOIN posts p ON p.id=ord.post_id
  JOIN users oth ON oth.id = IF(ord.client_id=:u3, ord.commissioner_id, ord.client_id)
  WHERE (ord.client_id=:u4 OR ord.commissioner_id=:u5)
    AND (ord.status IN ('active','delivered','disputed','completed') OR EXISTS (SELECT 1 FROM order_messages m WHERE m.order_id=ord.id))
  ORDER BY (unread > 0) DESC, COALESCE(last_at, ord.started_at) DESC");
$q->execute([':u1'=>$uid, ':u2'=>$uid, ':u3'=>$uid, ':u4'=>$uid, ':u5'=>$uid]);
$rows = $q->fetchAll();

// Pick the open conversation (default: the first one).
$sel = null;
$want = (int)($_GET['id'] ?? 0);
foreach ($rows as $r) if ((int)$r['id'] === $want) $sel = $r;
if (!$sel && $rows) $sel = $rows[0];

// Render the chat before the nav so opening it clears the unread badge.
$panel = '';
if ($sel) {
  $panel = chat_panel($sel, $uid);
  foreach ($rows as &$r) if ($r['id'] === $sel['id']) $r['unread'] = 0;
  unset($r);
}
?>
<!doctype html>
<html lang="en"><head><title>Messages · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/chat.css"></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">
  <div class="page-head"><h1>Messages</h1><p>Your chats with the people you're working with.</p></div>

  <?php if (!$rows): ?>
    <section class="panel"><p class="empty">No conversations yet. A chat opens once a commissioner agrees to an order.</p></section>
  <?php else: ?>
  <div class="msgs">
    <nav class="panel convos" aria-label="Conversations">
      <ul>
        <?php foreach ($rows as $r):
          $on = $sel && $r['id'] === $sel['id'];
          $prev = $r['last_body'] !== null
            ? (((int)$r['last_sender'] === $uid ? 'You: ' : '') . mb_strimwidth(str_replace("\n", ' ', $r['last_body']), 0, 70, '…'))
            : 'No messages yet';
        ?>
        <li><a class="convo<?= $on ? ' on' : '' ?><?= $r['unread'] ? ' unread' : '' ?>" href="messages.php?id=<?= (int)$r['id'] ?>"<?= $on ? ' aria-current="page"' : '' ?>>
          <span class="convo-top"><b><?= e($r['other_name']) ?></b>
            <?php if ($r['unread']): ?><span class="badge"><?= (int)$r['unread'] ?></span>
            <?php elseif ($r['last_at']): ?><time><?= e(chat_time($r['last_at'])) ?></time><?php endif; ?></span>
          <span class="convo-title"><?= e($r['title']) ?></span>
          <span class="convo-prev"><?= e($prev) ?></span>
        </a></li>
        <?php endforeach; ?>
      </ul>
    </nav>

    <div class="convo-main">
      <div class="convo-head">
        <div><h2><?= e($sel['other_name']) ?></h2><small class="muted"><?= e($sel['title']) ?></small></div>
        <div class="row-end"><?= status_tag($sel['status']) ?><a class="btn ghost" href="order.php?id=<?= (int)$sel['id'] ?>">View order</a></div>
      </div>
      <?= $panel ?>
    </div>
  </div>
  <?php endif; ?>
</main>
<?php if ($sel): ?><script src="<?= BASE_URL ?>assets/js/chat.js" defer></script><?php endif; ?>
</body></html>