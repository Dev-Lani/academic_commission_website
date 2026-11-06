<?php
// Order lifecycle: lazy expiry and simulated escrow.

// Lazy expiry: kapag lumampas ang agree window, balik sa Open ang post.
// (Papalitan ng cron job sa Increment 6.)
function expire_orders(){
  global $pdo;
  $q = $pdo->prepare("SELECT id, post_id, client_id FROM orders WHERE status='pending_agree' AND agree_by < ?");
  $q->execute([date('Y-m-d H:i:s')]);
  foreach ($q->fetchAll() as $o) {
    $pdo->beginTransaction();
    $u = $pdo->prepare("UPDATE orders SET status='expired' WHERE id=? AND status='pending_agree'");
    $u->execute([$o['id']]);
    if ($u->rowCount()) {
      $pdo->prepare("UPDATE posts SET status='open' WHERE id=? AND status='claimed'")->execute([$o['post_id']]);
      notify($o['client_id'], $o['post_id'], 'expired', $o['id']);
    }
    $pdo->commit();
  }
}

// Simulated escrow release: payout minus the platform cut for the commissioner's tier.
// Call inside a transaction, after the order status is set to completed.
function release_escrow($o){
  global $pdo;
  $t = $pdo->prepare('SELECT tier FROM users WHERE id=?');
  $t->execute([$o['commissioner_id']]);
  $pct = TIER_FEE[$t->fetchColumn()] ?? PLATFORM_FEE_PCT;
  $fee = (int)round($o['price'] * $pct / 100);
  $now = date('Y-m-d H:i:s');
  $pdo->prepare("UPDATE orders SET escrow_status='released', fee=?, completed_at=? WHERE id=?")->execute([$fee, $now, $o['id']]);
  $t = $pdo->prepare('INSERT INTO transactions (order_id,user_id,type,amount,created_at) VALUES (?,?,?,?,?)');
  $t->execute([$o['id'], $o['commissioner_id'], 'release', (int)$o['price'] - $fee, $now]);
  $t->execute([$o['id'], null, 'fee', $fee, $now]);
  recompute_tier($o['commissioner_id']);
  recompute_tier($o['client_id']);
}

// Admin decided for the client: the escrow goes back to them.
function refund_escrow($o){
  global $pdo;
  $now = date('Y-m-d H:i:s');
  $pdo->prepare("UPDATE orders SET escrow_status='refunded', completed_at=? WHERE id=?")->execute([$now, $o['id']]);
  $pdo->prepare('INSERT INTO transactions (order_id,user_id,type,amount,created_at) VALUES (?,?,?,?,?)')
      ->execute([$o['id'], $o['client_id'], 'refund', (int)$o['price'], $now]);
  recompute_tier($o['commissioner_id']);
  recompute_tier($o['client_id']);
}

// Lazy auto-release: walang sagot ang client sa review window = released na ang payment.
// (Cron job sa Increment 6.)
function expire_reviews(){
  global $pdo;
  $q = $pdo->prepare("SELECT * FROM orders WHERE status='delivered' AND review_by < ?");
  $q->execute([date('Y-m-d H:i:s')]);
  foreach ($q->fetchAll() as $o) {
    $pdo->beginTransaction();
    $u = $pdo->prepare("UPDATE orders SET status='completed' WHERE id=? AND status='delivered'");
    $u->execute([$o['id']]);
    if ($u->rowCount()) {
      release_escrow($o);
      $pdo->prepare("UPDATE posts SET status='completed' WHERE id=?")->execute([$o['post_id']]);
      notify($o['commissioner_id'], $o['post_id'], 'released', $o['id']);
      notify($o['client_id'], $o['post_id'], 'released', $o['id']);
    }
    $pdo->commit();
  }
}

