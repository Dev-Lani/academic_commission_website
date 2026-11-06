<?php
// Login guards and notifications.

function require_login(){
  global $pdo;
  if (empty($_SESSION['uid'])) { redirect('login.php'); }
  $q = $pdo->prepare('SELECT id, username, role, tier, is_verified FROM users WHERE id=?');
  $q->execute([$_SESSION['uid']]);
  $me = $q->fetch();
  
  if (!$me) { session_destroy(); redirect('login.php'); }
  expire_orders();
  expire_reviews();
  return $me;
}

function require_admin(){
  $me = require_login();
  if ($me['role'] !== 'admin') { redirect('dashboard.php'); }
  return $me;
}

function notify($uid, $postId, $type, $orderId = null){
  global $pdo;
  $pdo->prepare("INSERT INTO notifications (user_id,post_id,type,order_id,is_read) VALUES (?,?,?,?,0)
                 ON DUPLICATE KEY UPDATE is_read=0, order_id=VALUES(order_id), updated_at=NOW()")
      ->execute([$uid, $postId, $type, $orderId]);
}

