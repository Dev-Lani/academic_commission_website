<?php
// Shared chat helpers. Used by order.php (first render) and chat.php (polling + sending).

const CHAT_MAX_LEN = 2000;

function chat_time($ts){
  $t = strtotime($ts);
  return date('Y-m-d', $t) === date('Y-m-d') ? date('g:i A', $t) : date('M j, g:i A', $t);
}

// Messages with id > $after, oldest first. With $after = 0 you get the latest $limit messages.
function chat_messages($orderId, $meId, $after = 0, $limit = 200){
  global $pdo;
  $limit = (int)$limit;
  if ($after > 0) {
    $q = $pdo->prepare("SELECT m.id, m.sender_id, u.username, m.body, m.created_at
      FROM order_messages m JOIN users u ON u.id=m.sender_id
      WHERE m.order_id=? AND m.id>? ORDER BY m.id ASC LIMIT $limit");
    $q->execute([$orderId, $after]);
  } else {
    $q = $pdo->prepare("SELECT * FROM (
        SELECT m.id, m.sender_id, u.username, m.body, m.created_at
        FROM order_messages m JOIN users u ON u.id=m.sender_id
        WHERE m.order_id=? ORDER BY m.id DESC LIMIT $limit) t ORDER BY id ASC");
    $q->execute([$orderId]);
  }
  return array_map(fn($m) => [
    'id'   => (int)$m['id'],
    'mine' => (int)$m['sender_id'] === (int)$meId,
    'name' => $m['username'],
    'body' => $m['body'],
    'time' => chat_time($m['created_at']),
  ], $q->fetchAll());
}

// Remember how far this user has read in this order's chat.
function chat_mark_read($orderId, $uid, $lastId){
  global $pdo;
  if ($lastId <= 0) return;
  $pdo->prepare("INSERT INTO chat_reads (order_id, user_id, last_id) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE last_id=GREATEST(last_id, VALUES(last_id))")
      ->execute([$orderId, $uid, $lastId]);
}

// Unread chat messages across all of this user's orders (for the nav badge).
function messages_unread($uid){
  global $pdo;
  $q = $pdo->prepare("SELECT COUNT(*) FROM order_messages m JOIN orders o ON o.id=m.order_id
    WHERE (o.client_id=? OR o.commissioner_id=?) AND m.sender_id<>?
      AND m.id > COALESCE((SELECT r.last_id FROM chat_reads r WHERE r.order_id=m.order_id AND r.user_id=?), 0)");
  $q->execute([$uid, $uid, $uid, $uid]);
  return (int)$q->fetchColumn();
}

// Chat is writable only while the order is in progress; it stays readable afterwards.
function chat_open($status){ return in_array($status, ['active','delivered','disputed'], true); }

function chat_panel($order, $meId){
  $msgs = chat_messages((int)$order['id'], $meId);
  $last = $msgs ? end($msgs)['id'] : 0;
  chat_mark_read((int)$order['id'], $meId, $last);
  $open = chat_open($order['status']);
  ob_start(); ?>
  <section class="panel chat" id="chat"
           data-order="<?= (int)$order['id'] ?>" data-last="<?= (int)$last ?>">
    <h2>Chat</h2>
    <ul class="chat-log" id="chat-log" aria-live="polite" tabindex="0">
      <?php foreach ($msgs as $m): ?>
        <li class="msg <?= $m['mine'] ? 'mine' : 'theirs' ?>" data-id="<?= $m['id'] ?>">
          <div class="bubble"><?= e($m['body']) ?></div>
          <small><?= $m['mine'] ? 'You' : e($m['name']) ?> · <?= e($m['time']) ?></small>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="empty chat-empty" id="chat-empty" <?= $msgs ? 'hidden' : '' ?>>No messages yet. Say hi and agree on the details here.</p>
    <?php if ($open): ?>
      <form method="post" action="chat.php" class="chat-form" id="chat-form">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
        <label class="sr" for="chat-body">Message</label>
        <textarea id="chat-body" name="body" rows="2" maxlength="<?= CHAT_MAX_LEN ?>" placeholder="Write a message. Enter to send, Shift+Enter for a new line." required></textarea>
        <button class="btn sm">Send</button>
      </form>
    <?php else: ?>
      <p class="chat-closed">This chat is closed. The log is kept for review.</p>
    <?php endif; ?>
    <p class="hint chat-note">Messages can't be edited or deleted. The log is kept as evidence if there is a dispute.</p>
  </section>
  <?php
  return ob_get_clean();
}