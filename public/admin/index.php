<?php
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$me = require_admin();

$base = "SELECT d.order_id, d.created_at, d.resolution, p.title, ord.price, cl.username AS client_name, cm.username AS comm_name
  FROM disputes d JOIN orders ord ON ord.id=d.order_id JOIN posts p ON p.id=ord.post_id
  JOIN users cl ON cl.id=ord.client_id JOIN users cm ON cm.id=ord.commissioner_id ";
$open  = $pdo->query($base . "WHERE d.status='open' ORDER BY d.created_at")->fetchAll();
$done  = $pdo->query($base . "WHERE d.status='resolved' ORDER BY d.resolved_at DESC LIMIT 10")->fetchAll();
$users = $pdo->query("SELECT id, username, role, tier, is_verified FROM users WHERE role<>'admin' ORDER BY created_at DESC LIMIT 100")->fetchAll();

// Numbers for the stat tiles
$esc = $pdo->query("SELECT COALESCE(SUM(price),0) AS total, COUNT(*) AS n FROM orders WHERE escrow_status='held'")->fetch();
$acc = ['client' => 0, 'commissioner' => 0, 'unverified' => 0];
foreach ($pdo->query("SELECT role, COUNT(*) AS n, SUM(is_verified=0) AS nv FROM users WHERE role<>'admin' GROUP BY role") as $r) {
  $acc[$r['role']] = (int)$r['n'];
  $acc['unverified'] += (int)$r['nv'];
}
$late = 0; // open disputes waiting more than 48 hours
foreach ($open as $d) if (time() - strtotime($d['created_at']) > 172800) $late++;

// "5m", "3h", "2d"
function ad_ago($ts){
  $s = max(0, time() - strtotime($ts));
  if ($s < 3600)  return max(1, intdiv($s, 60)) . 'm';
  if ($s < 86400) return intdiv($s, 3600) . 'h';
  return intdiv($s, 86400) . 'd';
}

$msg = $_GET['msg'] ?? '';
?>
<!doctype html>
<html lang="en"><head><title>Admin · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/profile.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css"></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">

  <section class="ad-hero">
    <span class="ad-ico" aria-hidden="true">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg>
    </span>
    <div class="ad-hero-t">
      <h1>Admin console</h1>
      <p>Resolve disputes, verify accounts and keep tiers fair.</p>
    </div>
    <div class="ad-hero-a"><a class="ad-btn line" href="<?= BASE_URL ?>profile.php?u=<?= urlencode($me['username']) ?>">My admin profile</a></div>
  </section>

  <?php if ($msg === 'resolved'): ?><div class="alert ok" role="status">Dispute resolved.</div><?php endif; ?>
  <?php if ($msg === 'recomputed'): ?><div class="alert ok" role="status">Tiers recalculated for all users.</div><?php endif; ?>

  <section class="ad-stats" aria-label="Overview">
    <div class="ad-stat col<?= $open ? ' hot' : '' ?>">
      <small>Open disputes</small><b><?= count($open) ?></b>
      <small><?= $late ? e(plural($late, 'waiting over 48 hours', 'waiting over 48 hours')) : ($open ? 'All within 48 hours' : 'All clear') ?></small>
    </div>
    <div class="ad-stat col">
      <small>Held in escrow</small><b><?= money($esc['total']) ?></b>
      <small>across <?= e(plural((int)$esc['n'], 'active order', 'active orders')) ?></small>
    </div>
    <div class="ad-stat col">
      <small>Accounts</small><b><?= $acc['client'] + $acc['commissioner'] ?></b>
      <small><?= (int)$acc['client'] ?> clients · <?= (int)$acc['commissioner'] ?> commissioners</small>
    </div>
    <div class="ad-stat col warn">
      <small>Not verified yet</small><b><?= (int)$acc['unverified'] ?></b>
      <small>Verify to show the badge</small>
    </div>
  </section>

  <div class="ad-cols">
    <div class="ad-main">
      <section>
        <div class="ad-sh"><h2>Open disputes (<?= count($open) ?>)</h2><small>Oldest first</small></div>
        <?php if (!$open): ?><p class="ad-empty">All clear. No open disputes.</p><?php else: ?>
        <ul class="ad-list"><?php foreach ($open as $d): $isLate = time() - strtotime($d['created_at']) > 172800; ?>
          <li class="ad-case<?= $isLate ? ' late' : '' ?>">
            <span class="ad-dot" aria-hidden="true"></span>
            <div class="ad-ct"><b><?= e($d['title']) ?></b>
              <small><?= e($d['client_name']) ?> vs <?= e($d['comm_name']) ?> · <?= money($d['price']) ?> in escrow</small></div>
            <span class="ad-age" title="Opened <?= e(date('M j, g:i A', strtotime($d['created_at']))) ?>"><?= $isLate ? 'Waiting ' : 'Opened ' ?><?= e(ad_ago($d['created_at'])) ?><?= $isLate ? '' : ' ago' ?></span>
            <a class="ad-btn pri" href="order.php?id=<?= (int)$d['order_id'] ?>">Review</a>
          </li>
        <?php endforeach; ?></ul><?php endif; ?>
      </section>

      <section>
        <div class="ad-sh"><h2>Recently resolved</h2></div>
        <?php if (!$done): ?><p class="ad-empty">Nothing yet.</p><?php else: ?>
        <div class="ad-card"><ul class="ad-rowl"><?php foreach ($done as $d): $rel = $d['resolution'] === 'release'; ?>
          <li>
            <div class="ad-ct"><b><?= e($d['title']) ?></b><small><?= e($d['client_name']) ?> vs <?= e($d['comm_name']) ?></small></div>
            <span class="ad-pill <?= $rel ? 'ok' : 'bad' ?>"><?= $rel ? 'Paid to commissioner' : 'Refunded to client' ?></span>
            <a href="order.php?id=<?= (int)$d['order_id'] ?>"><b>View</b></a>
          </li>
        <?php endforeach; ?></ul></div><?php endif; ?>
      </section>
    </div>

    <aside class="ad-side">
      <section class="ad-card" id="ad-acc">
        <div class="ad-acc-h">
          <h2>Accounts</h2>
          <form method="post" action="action.php">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <button class="ad-btn sm" name="action" value="recompute">Recalculate tiers</button>
          </form>
        </div>
        <div class="ad-tools">
          <button type="button" class="ad-tab on" data-f="all">All</button>
          <button type="button" class="ad-tab" data-f="client">Clients</button>
          <button type="button" class="ad-tab" data-f="commissioner">Commissioners</button>
          <button type="button" class="ad-tab" data-f="unverified">Unverified</button>
          <input type="search" class="ad-search" id="ad-q" placeholder="Search username" aria-label="Search accounts" autocomplete="off">
        </div>
        <ul class="ad-users" id="ad-users"><?php foreach ($users as $u): ?>
          <li data-role="<?= e($u['role']) ?>" data-ver="<?= $u['is_verified'] ? '1' : '0' ?>" data-name="<?= e(mb_strtolower($u['username'])) ?>">
            <span class="ad-ua" aria-hidden="true"><?= e(strtoupper(mb_substr($u['username'], 0, 1))) ?></span>
            <div class="ad-ub"><a href="<?= BASE_URL ?>profile.php?u=<?= urlencode($u['username']) ?>"><?= e($u['username']) ?></a>
              <small><?= e(ucfirst($u['role'])) ?> · <?= e($u['tier']) ?></small></div>
            <?php if ($u['is_verified']): ?><span class="ad-pill ok">Verified</span><?php endif; ?>
            <form method="post" action="action.php">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="verify">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <input type="hidden" name="value" value="<?= $u['is_verified'] ? 0 : 1 ?>">
              <button class="ad-btn sm"><?= $u['is_verified'] ? 'Remove' : 'Verify' ?></button>
            </form>
          </li>
        <?php endforeach; ?></ul>
        <p class="ad-none" id="ad-none" hidden>No accounts match.</p>
        <p class="ad-note">Shows the latest 100 accounts.</p>
      </section>
    </aside>
  </div>
</main>
<script>
// Filter tabs + search for the accounts list (display only; nothing is sent to the server).
(function(){
  var tabs = document.querySelectorAll('.ad-tab'), rows = document.querySelectorAll('#ad-users li'),
      q = document.getElementById('ad-q'), none = document.getElementById('ad-none'), f = 'all';
  function apply(){
    var term = q.value.trim().toLowerCase(), shown = 0;
    rows.forEach(function(li){
      var ok = (f === 'all' || (f === 'unverified' ? li.dataset.ver === '0' : li.dataset.role === f))
               && (term === '' || li.dataset.name.indexOf(term) !== -1);
      li.hidden = !ok; if (ok) shown++;
    });
    none.hidden = shown !== 0;
  }
  tabs.forEach(function(t){
    t.addEventListener('click', function(){
      f = t.dataset.f;
      tabs.forEach(function(x){ x.classList.toggle('on', x === t); });
      apply();
    });
  });
  q.addEventListener('input', apply);
})();
</script>
</body></html>