<?php
// Shared chat helpers. Used by order.php (first render) and chat.php (polling + sending).

const CHAT_MAX_LEN = 2000;

function chat_time($ts){
  $t = strtotime($ts);
  return date('Y-m-d', $t) === date('Y-m-d') ? date('g:i A', $t) : date('M j, g:i A', $t);
}

function chat_day_label($ts){
  $d = date('Y-m-d', $ts);
  if ($d === date('Y-m-d')) return 'Today';
  if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
  return date('Y', $ts) === date('Y') ? date('M j', $ts) : date('M j, Y', $ts);
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
  return array_map(function ($m) use ($meId) {
    $t = strtotime($m['created_at']);
    return [
      'id'       => (int)$m['id'],
      'mine'     => (int)$m['sender_id'] === (int)$meId,
      'name'     => $m['username'],
      'body'     => $m['body'],
      'time'     => chat_time($m['created_at']),
      'ts'       => $t,
      'day'      => date('Y-m-d', $t),
      'dayLabel' => chat_day_label($t),
    ];
  }, $q->fetchAll());
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
  $lastDay = $msgs ? end($msgs)['day'] : '';
  chat_mark_read((int)$order['id'], $meId, $last);
  $open = chat_open($order['status']);
  // Magkasunod na message ng iisang tao (within 5 min, parehong araw) = isang group
  $same = fn($a, $b) => $a && $b && $a['day'] === $b['day'] && $a['mine'] === $b['mine'] && $b['ts'] - $a['ts'] <= 300;
  ob_start(); ?>
  <section class="panel chat" id="chat"
           data-order="<?= (int)$order['id'] ?>" data-last="<?= (int)$last ?>" data-day="<?= e($lastDay) ?>">
    <h2>Chat</h2>
    <div class="chat-body">
      <ul class="chat-log" id="chat-log" aria-live="polite" tabindex="0">
        <?php $day = '';
        foreach ($msgs as $i => $m):
          if ($m['day'] !== $day): $day = $m['day']; ?>
            <li class="chat-day" role="separator"><?= e($m['dayLabel']) ?></li>
          <?php endif;
          $cont = $same($msgs[$i - 1] ?? null, $m);
          $next = $same($m, $msgs[$i + 1] ?? null); ?>
          <li class="msg <?= $m['mine'] ? 'mine' : 'theirs' ?><?= $cont ? ' cont' : '' ?><?= $next ? ' cont-next' : '' ?>"
              data-id="<?= $m['id'] ?>" data-day="<?= e($m['day']) ?>" data-ts="<?= (int)$m['ts'] ?>" data-mine="<?= $m['mine'] ? 1 : 0 ?>">
            <div class="bubble"><?= e($m['body']) ?></div>
            <small><?= $m['mine'] ? 'You' : e($m['name']) ?> · <?= e($m['time']) ?></small>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="chat-empty" id="chat-empty"<?= $msgs ? ' hidden' : '' ?>>
        <span class="eic" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg></span>
        <?php if ($open): ?>
          <b>Wala pang messages</b><p>Mag-hi at pag-usapan ang detalye ng order dito.</p>
        <?php else: ?>
          <b>Walang messages sa chat na ito</b><p>Read-only ang chat na ito. Kung may usapan dati, makikita pa rin dito.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($open): ?>
      <form method="post" action="chat.php" class="chat-form" id="chat-form">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
        <label class="sr" for="chat-body">Message</label>
        <textarea id="chat-body" name="body" rows="2" maxlength="<?= CHAT_MAX_LEN ?>" placeholder="Write a message. Enter to send, Shift+Enter for a new line." required></textarea>
        <button class="btn sm">Send</button>
      </form>
      <p class="hint chat-note">Messages can't be edited or deleted. The log is kept as evidence if there is a dispute.</p>
    <?php else: ?>
      <p class="chat-closed"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
        <span>Closed chat. Naka-save ang log para sa review kapag may dispute.</span></p>
    <?php endif; ?>
  </section>
  <?php
  return ob_get_clean();
}