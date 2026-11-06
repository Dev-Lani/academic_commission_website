<?php
// Stats, tiers, trust score, badges, ratings.

// Everything tiers, badges and trust score are based on.
function user_stats($uid){
  global $pdo;
  $q = $pdo->prepare("SELECT u.role, u.is_verified,
    (SELECT COUNT(*) FROM orders o WHERE o.status='completed' AND (o.client_id=u.id OR o.commissioner_id=u.id)) AS completed,
    (SELECT COUNT(*) FROM orders o WHERE o.status='completed' AND o.commissioner_id=u.id AND o.delivered_at IS NOT NULL) AS delivered,
    (SELECT COUNT(*) FROM orders o WHERE o.status='completed' AND o.commissioner_id=u.id AND o.delivered_at IS NOT NULL AND o.delivered_at <= o.deliver_by) AS ontime,
    (SELECT AVG(r.stars) FROM ratings r WHERE r.ratee_id=u.id) AS rating,
    (SELECT COUNT(*) FROM ratings r WHERE r.ratee_id=u.id) AS rating_n,
    (SELECT COUNT(*) FROM disputes d JOIN orders o ON o.id=d.order_id
       WHERE d.status='resolved' AND ((d.resolution='refund' AND o.commissioner_id=u.id) OR (d.resolution='release' AND o.client_id=u.id))) AS lost
    FROM users u WHERE u.id=?");
  $q->execute([$uid]);
  $s = $q->fetch();
  if (!$s) return null;
  foreach (['completed','delivered','ontime','rating_n','lost'] as $k) $s[$k] = (int)$s[$k];
  $s['rating'] = $s['rating'] === null ? null : (float)$s['rating'];
  $s['ontime_pct'] = $s['delivered'] ? $s['ontime'] / $s['delivered'] : 0;
  return $s;
}

// Tier from stats (concept sections 5.1 to 5.3). A lost dispute drops a commissioner one tier.
function compute_tier($s){
  $c = $s['completed']; $r = (float)$s['rating']; $lost = $s['lost'];
  if ($s['role'] === 'commissioner') {
    $i = 0;
    if ($c >= 5  && $r >= 4.0) $i = 1;
    if ($c >= 20 && $r >= 4.5 && $s['ontime_pct'] >= 0.9) $i = 2;
    if ($c >= 50 && $r >= 4.8 && $lost == 0) $i = 3;
    if ($lost > 0) $i = max(0, $i - 1);
    return TIER_ORDER[$i];
  }
  $i = 0;
  if ($lost == 0) {
    if ($c >= 3)  $i = 1;
    if ($c >= 10 && $r >= 4.5) $i = 2;
    if ($c >= 25) $i = 3;
  }
  return CLIENT_TIERS[$i];
}

function recompute_tier($uid){
  global $pdo;
  $s = user_stats($uid);
  if (!$s || !in_array($s['role'], ['client','commissioner'], true)) return;
  $pdo->prepare('UPDATE users SET tier=? WHERE id=?')->execute([compute_tier($s), $uid]);
}

// 0 to 100: completed jobs (40) + rating (40) + dispute record (20).
function trust_score($s){
  $c = min($s['completed'], 20) / 20 * 40;
  $r = $s['rating_n'] ? ($s['rating'] / 5) * 40 : 0;
  $d = max(0, 20 - 10 * $s['lost']);
  return (int)round($c + $r + $d);
}

// Badges are computed live, so they disappear by themselves when a dispute is lost.
function user_badges($s){
  $b = [];
  if ($s['is_verified']) $b[] = 'Verified';
  if ($s['role'] === 'commissioner') {
    $r = (float)$s['rating'];
    $b[] = ($s['completed'] >= 20 && $r >= 4.5) ? 'Top Writer' : (($s['completed'] >= 5 && $r >= 4.0) ? 'Trusted' : 'Newcomer');
    if ($s['delivered'] >= 5 && $s['ontime_pct'] >= 0.9) $b[] = 'On-time';
  }
  if ($s['completed'] >= 1 && $s['lost'] == 0) $b[] = 'Dispute-free';
  return $b;
}

// A completed order this user has not rated yet (ratings are required).
function unrated_order($uid){
  global $pdo;
  $q = $pdo->prepare("SELECT o.id FROM orders o WHERE o.status='completed' AND (o.client_id=? OR o.commissioner_id=?)
    AND NOT EXISTS (SELECT 1 FROM ratings r WHERE r.order_id=o.id AND r.rater_id=?) LIMIT 1");
  $q->execute([$uid, $uid, $uid]);
  return $q->fetchColumn() ?: null;
}

