<?php
// Admin actions: verify / un-verify an account, recalculate every tier, resolve a dispute.
// Save as public/admin/action.php. Every form in admin/index.php and admin/order.php posts here.
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) { redirect('admin/'); }
$action = $_POST['action'] ?? '';

/* ---------- Verify / remove the badge ---------- */
if ($action === 'verify') {
  $uid = (int)($_POST['user_id'] ?? 0);
  $val = ($_POST['value'] ?? '') === '1' ? 1 : 0;
  $pdo->prepare("UPDATE users SET is_verified=? WHERE id=? AND role<>'admin'")->execute([$val, $uid]);
  redirect('admin/?msg=verified#ad-acc');
}

/* ---------- Recalculate tiers for everyone ---------- */
if ($action === 'recompute') {
  $ids = $pdo->query("SELECT id FROM users WHERE role IN ('client','commissioner')")->fetchAll(PDO::FETCH_COLUMN);
  foreach ($ids as $id) recompute_tier((int)$id);
  redirect('admin/?msg=recomputed');
}

/* ---------- Resolve a dispute ---------- */
if ($action === 'resolve') {
  $id       = (int)($_POST['order_id'] ?? 0);
  $decision = $_POST['decision'] ?? '';
  $note     = trim((string)($_POST['note'] ?? ''));
  $back     = 'admin/order.php?id=' . $id;

  if (!in_array($decision, ['release', 'refund'], true) || $note === '' || mb_strlen($note) > 1000) {
    $_SESSION['flash_err'] = 'Choose a decision and write a note (max 1000 characters).';
    redirect($back);
  }

  $pdo->beginTransaction();
  $q = $pdo->prepare("SELECT * FROM orders WHERE id=? AND status='disputed' FOR UPDATE");
  $q->execute([$id]);
  $o = $q->fetch();
  $d = $pdo->prepare("SELECT id FROM disputes WHERE order_id=? AND status='open' FOR UPDATE");
  $d->execute([$id]);
  if (!$o || !$d->fetch()) { $pdo->rollBack(); redirect($back); }

  $now = date('Y-m-d H:i:s');
  // Mark the dispute resolved first, so the tier recalculation below already counts it.
  $pdo->prepare("UPDATE disputes SET status='resolved', resolution=?, admin_id=?, admin_note=?, resolved_at=? WHERE order_id=?")
      ->execute([$decision, $me['id'], $note, $now, $id]);

  if ($decision === 'release') {
    $pdo->prepare("UPDATE orders SET status='completed' WHERE id=?")->execute([$id]);
    release_escrow($o);                                   // payout minus platform fee, recomputes tiers
    $pdo->prepare("UPDATE posts SET status='completed' WHERE id=?")->execute([$o['post_id']]);
  } else {
    $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$id]);
    refund_escrow($o);                                    // money back to the client, recomputes tiers
    $pdo->prepare("UPDATE posts SET status='open' WHERE id=?")->execute([$o['post_id']]);
  }

  notify($o['client_id'], $o['post_id'], 'resolved', $id);
  notify($o['commissioner_id'], $o['post_id'], 'resolved', $id);
  $pdo->commit();
  redirect('admin/?msg=resolved');
}

redirect('admin/');