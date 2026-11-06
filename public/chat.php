<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();

$oid = (int)($_REQUEST['order_id'] ?? 0);
$q = $pdo->prepare('SELECT id, post_id, client_id, commissioner_id, status FROM orders WHERE id=?');
$q->execute([$oid]);
$ord = $q->fetch();
$member = $ord && in_array((int)$me['id'], [(int)$ord['client_id'], (int)$ord['commissioner_id']], true);

$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
function reply($data, $code = 200){
  http_response_code($code);
  header('Content-Type: application/json');
  header('Cache-Control: no-store');
  echo json_encode($data); exit;
}

if (!$member) {
  if ($ajax || $_SERVER['REQUEST_METHOD'] === 'GET') reply(['error' => 'Order not found.'], 404);
  header('Location: orders.php'); exit;
}

/* ---------- Poll: new messages since ?after=<id> ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $msgs = chat_messages($oid, $me['id'], (int)($_GET['after'] ?? 0));
  if ($msgs) chat_mark_read($oid, (int)$me['id'], end($msgs)['id']); // polling only runs while the chat is on screen
  reply(['messages' => $msgs, 'open' => chat_open($ord['status'])]);
}

/* ---------- Send ---------- */
$back = 'order.php?id=' . $oid . '#chat';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) {
  if ($ajax) reply(['error' => 'Session expired. Reload the page.'], 403);
  header('Location: ' . $back); exit;
}

$body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
$err = null;
if (!chat_open($ord['status']))              $err = 'This chat is closed.';
elseif ($body === '')                        $err = 'Write a message first.';
elseif (mb_strlen($body) > CHAT_MAX_LEN)     $err = 'Message is too long (max ' . CHAT_MAX_LEN . ' characters).';

if ($err) {
  if ($ajax) reply(['error' => $err], 422);
  header('Location: ' . $back); exit;
}

$pdo->prepare('INSERT INTO order_messages (order_id, sender_id, body, created_at) VALUES (?,?,?,?)')
    ->execute([$oid, $me['id'], $body, date('Y-m-d H:i:s')]);

if ($ajax) reply(['ok' => true]);
header('Location: ' . $back);