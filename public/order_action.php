<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) { header('Location: dashboard.php'); exit; }
$action = $_POST['action'] ?? '';

/* ---------- Client: accept an offer ---------- */
if ($action === 'accept') {
  if ($me['role'] !== 'client') { header('Location: dashboard.php'); exit; }
  $oid = (int)($_POST['offer_id'] ?? 0);

  $pdo->beginTransaction();
  $q = $pdo->prepare("SELECT o.*, p.user_id AS owner, p.status AS pstatus
                      FROM offers o JOIN posts p ON p.id=o.post_id WHERE o.id=? FOR UPDATE");
  $q->execute([$oid]);
  $o = $q->fetch();

  $dup = $pdo->prepare('SELECT id FROM orders WHERE offer_id=?');
  $dup->execute([$oid]);

  if (!$o || $o['owner'] != $me['id'] || $o['pstatus'] !== 'open' || $dup->fetch()) {
    $pdo->rollBack();
    header('Location: forum.php'); exit;
  }

  $now = time();
  $pdo->prepare("INSERT INTO orders (post_id,offer_id,client_id,commissioner_id,price,deliver_by,status,accepted_at,agree_by)
                 VALUES (?,?,?,?,?,?,'pending_agree',?,?)")
      ->execute([$o['post_id'], $oid, $me['id'], $o['user_id'], $o['price'], $o['deliver_by'],
                 date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + AGREE_WINDOW_MIN * 60)]);
  $orderId = (int)$pdo->lastInsertId();

  $pdo->prepare("UPDATE posts SET status='claimed' WHERE id=?")->execute([$o['post_id']]);
  notify($o['user_id'], $o['post_id'], 'accepted', $orderId);
  $pdo->commit();

  header('Location: order.php?id=' . $orderId); exit;
}

/* ---------- Either side: rate a completed order (required) ---------- */
if ($action === 'rate') {
  $id = (int)($_POST['order_id'] ?? 0);
  $back = 'order.php?id=' . $id;
  $q = $pdo->prepare("SELECT client_id, commissioner_id FROM orders WHERE id=? AND status='completed'");
  $q->execute([$id]);
  $ord = $q->fetch();
  if (!$ord || !in_array($me['id'], [$ord['client_id'], $ord['commissioner_id']])) { header('Location: orders.php'); exit; }

  $stars = (int)($_POST['stars'] ?? 0);
  $comment = trim($_POST['comment'] ?? '');
  $need = $stars < 3 ? 30 : 10;
  $err = '';
  if ($stars < 1 || $stars > 5)                                $err = 'Pick 1 to 5 stars.';
  elseif (mb_strlen($comment) < $need || mb_strlen($comment) > 1000)
    $err = $stars < 3 ? 'Ratings below 3 stars need a reason of at least 30 characters.' : 'Write a comment of 10 to 1000 characters.';
  if ($err) { $_SESSION['flash_err'] = $err; $_SESSION['old_comment'] = $comment; header('Location: ' . $back); exit; }

  $ratee = $me['id'] == $ord['client_id'] ? $ord['commissioner_id'] : $ord['client_id'];
  $pdo->prepare('INSERT IGNORE INTO ratings (order_id, rater_id, ratee_id, stars, comment, created_at) VALUES (?,?,?,?,?,?)')
      ->execute([$id, $me['id'], $ratee, $stars, $comment, date('Y-m-d H:i:s')]);
  recompute_tier($ratee);
  header('Location: ' . $back); exit;
}

/* ---------- Client: approve or dispute delivered work ---------- */
if (in_array($action, ['approve', 'dispute'], true)) {
  $id = (int)($_POST['order_id'] ?? 0);
  $back = 'order.php?id=' . $id;

  $pdo->beginTransaction();
  $q = $pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
  $q->execute([$id]);
  $ord = $q->fetch();

  if (!$ord || $ord['client_id'] != $me['id'] || $ord['status'] !== 'delivered') {
    $pdo->rollBack();
    header('Location: orders.php'); exit;
  }
  if (strtotime($ord['review_by']) < time()) { // auto-released na
    $pdo->rollBack();
    expire_reviews();
    header('Location: ' . $back); exit;
  }

  if ($action === 'approve') {
    $pdo->prepare("UPDATE orders SET status='completed' WHERE id=?")->execute([$id]);
    release_escrow($ord);
    $pdo->prepare("UPDATE posts SET status='completed' WHERE id=?")->execute([$ord['post_id']]);
    notify($ord['commissioner_id'], $ord['post_id'], 'approved', $id);
  } else {
    $reason = trim($_POST['reason'] ?? '');
    $err = '';
    if (!$ord['preview_started_at'])                         $err = 'Open the preview first. You can only dispute work you have looked at.';
    elseif (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) $err = 'Give a reason of 10 to 1000 characters.';
    if ($err) {
      $pdo->rollBack();
      $_SESSION['flash_err'] = $err; $_SESSION['old_reason'] = $reason;
      header('Location: ' . $back); exit;
    }
    $pdo->prepare("UPDATE orders SET status='disputed' WHERE id=?")->execute([$id]);
    $pdo->prepare('INSERT INTO disputes (order_id, opened_by, reason, created_at) VALUES (?,?,?,?)')
        ->execute([$id, $me['id'], $reason, date('Y-m-d H:i:s')]);
    notify($ord['commissioner_id'], $ord['post_id'], 'disputed', $id);
  }
  $pdo->commit();
  header('Location: ' . $back); exit;
}

/* ---------- Commissioner: agree or decline ---------- */
if (in_array($action, ['agree', 'decline'], true)) {
  $id = (int)($_POST['order_id'] ?? 0);

  $pdo->beginTransaction();
  $q = $pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
  $q->execute([$id]);
  $ord = $q->fetch();

  if (!$ord || $ord['commissioner_id'] != $me['id'] || $ord['status'] !== 'pending_agree') {
    $pdo->rollBack();
    header('Location: orders.php'); exit;
  }
  if (strtotime($ord['agree_by']) < time()) { // late na
    $pdo->rollBack();
    expire_orders();
    header('Location: order.php?id=' . $id); exit;
  }

  if ($action === 'agree') {
    $c = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE commissioner_id=? AND status IN ('active','delivered','disputed')");
    $c->execute([$me['id']]);
    $max = TIER_JOBS[$me['tier']] ?? 2;
    if ((int)$c->fetchColumn() >= $max) { // tier cap sa active jobs
      $pdo->rollBack();
      $_SESSION['flash_err'] = 'Your ' . $me['tier'] . ' tier allows ' . $max . ' active jobs at a time. Finish one first, or decline this order.';
      header('Location: order.php?id=' . $id); exit;
    }
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE orders SET status='active', escrow_status='held', started_at=? WHERE id=?")->execute([$now, $id]);
    $pdo->prepare("INSERT INTO transactions (order_id,user_id,type,amount,created_at) VALUES (?,?,'hold',?,?)")
        ->execute([$id, $ord['client_id'], $ord['price'], $now]); // simulated: client pays into escrow
    $pdo->prepare("UPDATE posts SET status='in_progress' WHERE id=?")->execute([$ord['post_id']]);
    notify($ord['client_id'], $ord['post_id'], 'agreed', $id);
  } else {
    $pdo->prepare("UPDATE orders SET status='declined' WHERE id=?")->execute([$id]);
    $pdo->prepare("UPDATE posts SET status='open' WHERE id=?")->execute([$ord['post_id']]);
    notify($ord['client_id'], $ord['post_id'], 'declined', $id);
  }
  $pdo->commit();
  header('Location: order.php?id=' . $id); exit;
}

header('Location: dashboard.php');