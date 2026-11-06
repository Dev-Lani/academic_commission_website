<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me   = require_login();
$pid  = (int)($_POST['post_id'] ?? 0);
$back = 'post.php?id=' . $pid;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok() || $me['role'] !== 'commissioner') {
  header('Location: ' . $back); exit;
}

$q = $pdo->prepare('SELECT * FROM posts WHERE id=?');
$q->execute([$pid]);
$post = $q->fetch();
if (!$post || $post['status'] !== 'open') { header('Location: forum.php'); exit; }

if (($_POST['action'] ?? '') === 'withdraw') {
  $pdo->prepare('DELETE FROM offers WHERE post_id=? AND user_id=?')->execute([$pid, $me['id']]);
  header('Location: ' . $back . '&msg=withdrawn'); exit;
}

$price   = (int)($_POST['price'] ?? 0);
$message = trim($_POST['message'] ?? '');
$sample  = trim($_POST['sample'] ?? '');
$raw     = trim($_POST['deliver_by'] ?? '');
$dl      = DateTime::createFromFormat('Y-m-d\TH:i', $raw);

$err = '';
if (unrated_order($me['id']))                            $err = 'Rate your completed order first (My orders), then send new offers.';
elseif ($price < 1)                                       $err = 'Enter your price in pesos.';
elseif (!$dl || $dl->getTimestamp() <= time())            $err = 'Pick a delivery date in the future.';
elseif ($dl->getTimestamp() > strtotime($post['deadline'])) $err = 'Your delivery date must be on or before the client\'s deadline.';
elseif (strlen($message) < 10 || strlen($message) > 1000) $err = 'Your message must be 10 to 1000 characters.';
elseif (strlen($sample) > 500)                            $err = 'Sample work must be 500 characters or fewer.';

if ($err) {
  $_SESSION['flash_err'] = $err;
  $_SESSION['old_offer'] = ['price'=>$price ?: '', 'deliver_by'=>$raw, 'message'=>$message, 'sample'=>$sample];
  header('Location: ' . $back); exit;
}

$q = $pdo->prepare('SELECT id FROM offers WHERE post_id=? AND user_id=?');
$q->execute([$pid, $me['id']]);
$isNew = !$q->fetch();

$pdo->beginTransaction();
$pdo->prepare('INSERT INTO offers (post_id,user_id,price,deliver_by,message,sample) VALUES (?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE price=VALUES(price), deliver_by=VALUES(deliver_by),
               message=VALUES(message), sample=VALUES(sample)')
    ->execute([$pid, $me['id'], $price, $dl->format('Y-m-d H:i:s'), $message, $sample ?: null]);

if ($isNew) { // edit lang ng offer = walang bagong notification
  $pdo->prepare("INSERT INTO notifications (user_id,post_id,type,is_read) VALUES (?,?,'offer',0)
                 ON DUPLICATE KEY UPDATE is_read=0, updated_at=NOW()")
      ->execute([$post['user_id'], $pid]);
}
$pdo->commit();

header('Location: ' . $back . '&msg=sent');