<?php
// JSON list of the user's chats. Used by the floating Messages bubble.
require dirname(__DIR__) . '/src/bootstrap.php';
$me  = require_login();
$uid = (int)$me['id'];

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
  ORDER BY (unread > 0) DESC, COALESCE(last_at, ord.started_at) DESC
  LIMIT 30");
$q->execute([':u1'=>$uid, ':u2'=>$uid, ':u3'=>$uid, ':u4'=>$uid, ':u5'=>$uid]);

$convos = [];
foreach ($q->fetchAll() as $r) {
  $convos[] = [
    'id'     => (int)$r['id'],
    'name'   => $r['other_name'],
    'title'  => $r['title'],
    'prev'   => $r['last_body'] !== null
                  ? (((int)$r['last_sender'] === $uid ? 'You: ' : '') . mb_strimwidth(str_replace("\n", ' ', $r['last_body']), 0, 60, '…'))
                  : 'No messages yet',
    'time'   => $r['last_at'] ? chat_time($r['last_at']) : '',
    'unread' => (int)$r['unread'],
    'open'   => chat_open($r['status']),
  ];
}

header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['convos' => $convos, 'unread' => messages_unread($uid)]);
