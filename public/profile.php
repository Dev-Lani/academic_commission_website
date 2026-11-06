<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();
$q = $pdo->prepare("SELECT id, username, role, tier, is_verified, created_at FROM users WHERE username=? AND role IN ('client','commissioner')");
$q->execute([$_GET['u'] ?? '']);
$u = $q->fetch();
if (!$u) { http_response_code(404); echo 'Profile not found. <a href="forum.php">Back to the forum</a>'; exit; }

$s = user_stats($u['id']);
$badges = user_badges($s);
$isComm = $u['role'] === 'commissioner';
$r = $pdo->prepare('SELECT r.stars, r.comment, r.created_at, rt.username FROM ratings r JOIN users rt ON rt.id=r.rater_id WHERE r.ratee_id=? ORDER BY r.id DESC LIMIT 50');
$r->execute([$u['id']]);
$reviews = $r->fetchAll();
?>
<!doctype html>
<html lang="en"><head><title><?= e($u['username']) ?> · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/profile.css"></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">
  <div class="page-head">
    <h1><?= e($u['username']) ?></h1>
    <p><?= $isComm ? 'Commissioner' : 'Client' ?> · member since <?= e(date('M Y', strtotime($u['created_at']))) ?></p>
  </div>
  <div class="layout">
    <section class="panel">
      <h2>Comments (<?= count($reviews) ?>)</h2>
      <?php if (!$reviews): ?><p class="empty">No ratings yet.</p><?php endif; ?>
      <ul class="rows">
        <?php foreach ($reviews as $v): ?>
          <li><div><b><?= str_repeat('★', (int)$v['stars']) . str_repeat('☆', 5 - (int)$v['stars']) ?>
              <span class="muted">by <?= e($v['username']) ?></span></b>
            <small><?= nl2br(e($v['comment'])) ?> · <?= e(date('M j, Y', strtotime($v['created_at']))) ?></small></div></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <aside class="panel">
      <h2>Reputation</h2>
      <div class="profile"><dl>
        <div><dt>Tier</dt><dd><span class="chip tier"><?= e($u['tier']) ?></span></dd></div>
        <div><dt>Trust score</dt><dd><?= trust_score($s) ?>/100</dd></div>
        <div><dt>Rating</dt><dd><?= $s['rating_n'] ? number_format($s['rating'], 1) . ' / 5 (' . $s['rating_n'] . ')' : 'No ratings yet' ?></dd></div>
        <div><dt>Completed orders</dt><dd><?= $s['completed'] ?></dd></div>
        <?php if ($isComm): ?><div><dt>On-time</dt><dd><?= $s['delivered'] ? round($s['ontime_pct'] * 100) . '%' : 'No deliveries yet' ?></dd></div><?php endif; ?>
        <div><dt>Lost disputes</dt><dd><?= $s['lost'] ?></dd></div>
        <div><dt>Badges</dt><dd class="badges"><?php foreach ($badges as $b): ?><span class="chip"><?= e($b) ?></span><?php endforeach; ?></dd></div>
      </dl></div>
    </aside>
  </div>
</main>
</body></html>