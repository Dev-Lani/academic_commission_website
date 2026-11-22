<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();
// Clients and commissioners are public. An admin profile can only be opened by the admin themselves.
$q = $pdo->prepare("SELECT id, username, role, tier, is_verified, created_at FROM users
  WHERE username=? AND (role IN ('client','commissioner') OR (role='admin' AND id=?))");
$q->execute([$_GET['u'] ?? '', $me['id']]);
$u = $q->fetch();
if (!$u) { http_response_code(404); echo 'Profile not found. <a href="forum.php">Back to the forum</a>'; exit; }

$isAdmin = $u['role'] === 'admin';
$isComm  = $u['role'] === 'commissioner';
$roleLabel = $isAdmin ? 'Administrator' : ($isComm ? 'Commissioner' : 'Client');

// Admins do not trade, so they have no stats, trust score or reviews. They get their own dashboard-style page.
if ($isAdmin) {
  $st = $pdo->prepare("SELECT COUNT(*) AS n, MAX(resolved_at) AS last FROM disputes WHERE admin_id=? AND status='resolved'");
  $st->execute([$u['id']]);
  $as = $st->fetch();
  $openN = (int)$pdo->query("SELECT COUNT(*) FROM disputes WHERE status='open'")->fetchColumn();
  $rc = $pdo->prepare("SELECT d.order_id, d.resolution, d.resolved_at, p.title, cl.username AS client_name, cm.username AS comm_name
    FROM disputes d JOIN orders o ON o.id=d.order_id JOIN posts p ON p.id=o.post_id
    JOIN users cl ON cl.id=o.client_id JOIN users cm ON cm.id=o.commissioner_id
    WHERE d.admin_id=? AND d.status='resolved' ORDER BY d.resolved_at DESC LIMIT 5");
  $rc->execute([$u['id']]);
  $recent = $rc->fetchAll();
} else {
  $s = user_stats($u['id']);
  $badges = user_badges($s);
  $r = $pdo->prepare('SELECT r.stars, r.comment, r.created_at, rt.username FROM ratings r JOIN users rt ON rt.id=r.rater_id WHERE r.ratee_id=? ORDER BY r.id DESC LIMIT 50');
  $r->execute([$u['id']]);
  $reviews = $r->fetchAll();
  $tr = trust_score($s);
  $dist = array_fill(1, 5, 0); // bilang ng bawat star rating
  $d = $pdo->prepare('SELECT stars, COUNT(*) AS n FROM ratings WHERE ratee_id=? GROUP BY stars');
  $d->execute([$u['id']]);
  foreach ($d as $row) $dist[(int)$row['stars']] = (int)$row['n'];
}
?>
<!doctype html>
<html lang="en"><head><title><?= e($u['username']) ?> · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/profile.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css"></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">
<?php if ($isAdmin): ?>
  <section class="ad-hero">
    <span class="ad-av" aria-hidden="true"><?= e(strtoupper(mb_substr($u['username'], 0, 1))) ?></span>
    <div class="ad-hero-t">
      <span class="ad-role"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/></svg>Administrator</span>
      <h1><?= e($u['username']) ?></h1>
      <p>Member since <time datetime="<?= e(date('Y-m-d', strtotime($u['created_at']))) ?>"><?= e(date('M Y', strtotime($u['created_at']))) ?></time> · Only you can see this page</p>
    </div>
    <div class="ad-hero-a">
      <a class="ad-btn hl" href="<?= BASE_URL ?>admin/">Open admin panel</a>
      <a class="ad-btn line" href="<?= BASE_URL ?>dashboard.php">Dashboard</a>
    </div>
  </section>

  <section class="ad-stats" aria-label="Your admin activity">
    <div class="ad-stat mini"><span class="ad-si ok" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l4 4 10-10"/></svg></span>
      <div><b><?= (int)$as['n'] ?></b><small>Disputes you resolved</small></div></div>
    <div class="ad-stat mini"><span class="ad-si bad" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 17h.01"/></svg></span>
      <div><b><?= $openN ?></b><small>Open disputes waiting</small></div></div>
    <div class="ad-stat mini"><span class="ad-si brand" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/></svg></span>
      <div><b><?= $as['last'] ? e(date('M j', strtotime($as['last']))) : '–' ?></b><small>Last decision</small></div></div>
  </section>

  <div class="ad-cols">
    <section class="ad-main">
      <div class="ad-card">
        <h2>Your recent decisions</h2>
        <?php if (!$recent): ?><p class="ad-note" style="border:0">You haven’t resolved any disputes yet.</p><?php else: ?>
        <ul class="ad-rowl"><?php foreach ($recent as $d): $rel = $d['resolution'] === 'release'; ?>
          <li>
            <div class="ad-ct"><b><?= e($d['title']) ?></b><small><?= e($d['client_name']) ?> vs <?= e($d['comm_name']) ?> · <?= e(date('M j', strtotime($d['resolved_at']))) ?></small></div>
            <span class="ad-pill <?= $rel ? 'ok' : 'bad' ?>"><?= $rel ? 'Paid to commissioner' : 'Refunded to client' ?></span>
            <a href="<?= BASE_URL ?>admin/order.php?id=<?= (int)$d['order_id'] ?>"><b>View</b></a>
          </li>
        <?php endforeach; ?></ul>
        <p class="ad-note">Shows your last 5 decisions.</p>
        <?php endif; ?>
      </div>
    </section>
    <aside class="ad-side">
      <section class="ad-card pad">
        <h2>Shortcuts</h2>
        <div class="ad-links">
          <a href="<?= BASE_URL ?>admin/">Review open disputes<?php if ($openN): ?><span class="badge"><?= $openN ?></span><?php endif; ?></a>
          <a href="<?= BASE_URL ?>admin/#ad-acc">Verify accounts</a>
          <a href="<?= BASE_URL ?>inbox.php">Notifications</a>
        </div>
      </section>
      <section class="ad-card pad">
        <h2>Account</h2>
        <div class="ad-kv">
          <div><small>Username</small><b><?= e($u['username']) ?></b></div>
          <div><small>Role</small><b>Administrator</b></div>
          <div><small>Member since</small><b><?= e(date('F Y', strtotime($u['created_at']))) ?></b></div>
        </div>
      </section>
    </aside>
  </div>
<?php else: ?>
  <section class="panel pf-hero<?= $isComm ? '' : ' pf-client' ?>">
    <div class="pf-top">
      <span class="pf-av" aria-hidden="true"><?= e(strtoupper(mb_substr($u['username'], 0, 1))) ?></span>
      <div class="pf-id">
        <h1><?= mid_trunc($u['username'], 6) ?></h1>
        <p class="pf-sub"><?= e($roleLabel) ?>, member since <time datetime="<?= e(date('Y-m-d', strtotime($u['created_at']))) ?>"><?= e(date('M Y', strtotime($u['created_at']))) ?></time></p>
        <div class="pf-chips">
          <span class="chip tier"><?= e($u['tier']) ?></span>
          <?php foreach ($badges as $b): ?><span class="chip"><?= e($b) ?></span><?php endforeach; ?>
        </div>
      </div>
      <div class="pf-trust">
        <div class="pf-ring" style="--p:<?= (int)$tr ?>" role="img" aria-label="Trust score <?= (int)$tr ?> out of 100"><b><?= (int)$tr ?></b></div>
        <div><b>Trust score</b>out of 100</div>
      </div>
    </div>
    <div class="pf-stats">
      <div class="pf-stat"><b><?= $s['rating_n'] ? e(number_format($s['rating'], 1)) : '–' ?></b><small><?= $s['rating_n'] ? e(plural($s['rating_n'], 'rating', 'ratings')) : 'No ratings yet' ?></small></div>
      <div class="pf-stat"><b><?= (int)$s['completed'] ?></b><small>Completed orders</small></div>
      <?php if ($isComm): ?><div class="pf-stat"><b><?= $s['delivered'] ? e(round($s['ontime_pct'] * 100) . '%') : '–' ?></b><small><?= $s['delivered'] ? 'Delivered on time' : 'No deliveries yet' ?></small></div><?php endif; ?>
      <div class="pf-stat"><b><?= (int)$s['lost'] ?></b><small>Lost disputes</small></div>
    </div>
  </section>

  <div class="layout">
    <div>
      <?= commissioner_about_html($u['username']) ?>
      <section class="panel pf-rev">
        <h2>Reviews (<?= count($reviews) ?>)</h2>
        <?php if (!$reviews): ?><p class="empty">No ratings yet.</p><?php endif; ?>
        <ul class="pf-list">
          <?php foreach ($reviews as $v): $n = (int)$v['stars']; ?>
            <li>
              <div class="pf-rt">
                <span class="pf-stars" role="img" aria-label="<?= $n ?> out of 5 stars"><?= str_repeat('★', $n) ?><i><?= str_repeat('★', 5 - $n) ?></i></span>
                <b><?= e($v['username']) ?></b>
                <time datetime="<?= e(date('Y-m-d', strtotime($v['created_at']))) ?>"><?= e(date('M j, Y', strtotime($v['created_at']))) ?></time>
              </div>
              <p class="pf-text"><q><?= nl2br(e($v['comment'])) ?></q></p>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    </div>

    <aside class="panel pf-dist">
      <h2>Rating breakdown</h2>
      <ul>
        <?php for ($i = 5; $i >= 1; $i--): ?>
          <li><span><?= $i ?> ★</span><i style="--w:<?= $s['rating_n'] ? round($dist[$i] / $s['rating_n'] * 100) : 0 ?>%"></i><b><?= $dist[$i] ?></b></li>
        <?php endfor; ?>
      </ul>
    </aside>
  </div>
<?php endif; ?>
</main>
</body></html>