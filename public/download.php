<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();
$id = (int)($_GET['id'] ?? 0);

$q = $pdo->prepare("SELECT ord.client_id, ord.commissioner_id, ord.status, x.final_name, x.final_stored
  FROM orders ord JOIN order_outputs x ON x.order_id=ord.id WHERE ord.id=?");
$q->execute([$id]);
$o = $q->fetch();
$isComm = $o && $o['commissioner_id'] == $me['id'];
if (!$o || (!$isComm && $o['client_id'] != $me['id'])) { http_response_code(404); echo 'File not found.'; exit; }
// The client only gets the final file once payment is released.
if (!$isComm && $o['status'] !== 'completed') { header('Location: order.php?id=' . $id); exit; }

$path = PRIVATE_DIR . $o['final_stored'];
if (!is_file($path)) { http_response_code(404); echo 'File not found.'; exit; }
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $o['final_name']) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);