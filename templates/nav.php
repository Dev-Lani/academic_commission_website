<?php
// Top bar + floating Messages bubble. Needs $me (from require_login()).
// A page can set $hideBubble = true before including this file (messages.php does).
$unread     = unread_count($me['id']);
$unreadMsgs = messages_unread($me['id']);
$path    = $_SERVER['SCRIPT_NAME'] ?? '';
$page    = basename($path);
$inAdmin = strpos($path, '/admin/') !== false;

$links = [
  ['dashboard.php',    'Dashboard',    ['dashboard.php'], 'home'],
  ['forum.php',        'Forum',        ['forum.php', 'post.php', 'create_post.php'], 'forum'],
  ['orders.php',       'My orders',    ['orders.php', 'order.php', 'preview.php'], 'orders'],
  ['messages.php',     'Messages',     ['messages.php'], 'msg'],
  ['transactions.php', 'Transactions', ['transactions.php'], 'tx'],
];
if ($me['role'] === 'admin') $links[] = ['admin/', 'Admin', [], 'shield'];

$sbIcons = [
  'home'   => '<path d="M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
  'forum'  => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
  'orders' => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/>',
  'msg'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
  'tx'     => '<path d="M3 7h14l-3-3M21 17H7l3 3"/>',
  'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
  'bell'   => '<path d="M6 9a6 6 0 1 1 12 0c0 6 2 7 2 7H4s2-1 2-7"/><path d="M10 19a2 2 0 0 0 4 0"/>',
  'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
  'out'    => '<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 8l-4 4 4 4M6 12h10"/>',
  'grid'   => '<rect x="4" y="4" width="6" height="6"/><rect x="14" y="4" width="6" height="6"/><rect x="4" y="14" width="6" height="6"/><rect x="14" y="14" width="6" height="6"/>',
];
$sbIco = fn($n) => '<svg class="sb-ic" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $sbIcons[$n] . '</svg>';

// Top subjects for the sidebar. Same cache key and query as forum.php, so it costs nothing extra.
$sbSubjects = cache_remember('forum_subjects', 30, fn() => $pdo->query("SELECT s.name, s.slug, COUNT(p.id) AS n
  FROM subjects s LEFT JOIN posts p ON p.subject_id=s.id AND p.status='open'
  GROUP BY s.id ORDER BY s.name")->fetchAll());
usort($sbSubjects, fn($a, $b) => $b['n'] <=> $a['n']);
$sbSubjects = array_slice($sbSubjects, 0, 8);
$sbSlug = ($page === 'forum.php' && is_string($_GET['s'] ?? null)) ? $_GET['s'] : '';

// Latest notifications for the bell dropdown
$nq = $pdo->prepare('SELECT n.type, n.is_read, n.order_id, p.id AS pid, p.title
  FROM notifications n JOIN posts p ON p.id=n.post_id
  WHERE n.user_id=? ORDER BY n.updated_at DESC LIMIT 5');
$nq->execute([$me['id']]);
$noteRows = $nq->fetchAll();
$noteLabels = ['offer' => 'New offers on', 'accepted' => 'Offer accepted on', 'agreed' => 'Order started:', 'declined' => 'Order declined:',
               'expired' => 'No response on', 'delivered' => 'Work delivered:', 'approved' => 'Work approved:',
               'disputed' => 'Dispute opened on', 'released' => 'Payment released:', 'resolved' => 'Dispute resolved:'];
?>
<script>try{if(JSON.parse(localStorage.getItem('acw.sb')||'{}').rail)document.documentElement.classList.add('sb-rail')}catch(e){}</script>
<header class="topnav"><div class="tn">
  <button type="button" class="ib tn-burger" id="sb-burger" aria-label="Open menu" aria-expanded="false">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
  </button>
  <a class="brand" href="<?= BASE_URL ?>dashboard.php">Academic Commission</a>

  <div class="tn-right">
    <?php if ($me['role'] === 'client'): ?>
      <a class="btn" href="<?= BASE_URL ?>create_post.php">+ New request</a>
    <?php elseif ($me['role'] === 'commissioner'): ?>
      <a class="btn ghost" href="<?= BASE_URL ?>forum.php">Browse requests</a>
    <?php endif; ?>

    <div class="tn-menu">
      <button type="button" class="ib" data-dd="dd-notes" aria-label="Notifications<?= $unread ? ', ' . $unread . ' unread' : '' ?>" aria-expanded="false">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9a6 6 0 1 1 12 0c0 6 2 7 2 7H4s2-1 2-7"/><path d="M10 19a2 2 0 0 0 4 0"/></svg>
        <?php if ($unread): ?><span class="badge ib-badge"><?= $unread ?></span><?php endif; ?>
      </button>
      <div class="dd dd-notes" id="dd-notes" hidden>
        <div class="dd-head"><b>Notifications</b></div>
        <?php if (!$noteRows): ?><p class="dd-empty">Nothing here yet.</p><?php endif; ?>
        <?php foreach ($noteRows as $r):
          $link = in_array($r['type'], ['offer', 'declined', 'expired'], true) || !$r['order_id']
                ? 'post.php?id=' . $r['pid'] : 'order.php?id=' . $r['order_id']; ?>
          <a class="dd-item<?= $r['is_read'] ? '' : ' unread' ?>" href="<?= BASE_URL . e($link) ?>">
            <span><b><?= e(($noteLabels[$r['type']] ?? 'Update on') . ' “' . $r['title'] . '”') ?></b></span>
          </a>
        <?php endforeach; ?>
        <a class="dd-foot" href="<?= BASE_URL ?>inbox.php">See all notifications</a>
      </div>
    </div>

    <div class="tn-menu">
      <button type="button" class="tn-user" data-dd="dd-user" aria-expanded="false" aria-label="Account menu">
        <span class="av"><?= e(strtoupper(mb_substr($me['username'], 0, 1))) ?></span>
        <b><?= e($me['username']) ?></b>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
      </button>
      <div class="dd dd-user" id="dd-user" hidden>
        <div class="dd-head"><b><?= e($me['username']) ?></b>
          <small><?= e(ucfirst($me['role'])) ?><?= $me['role'] !== 'admin' ? ' · ' . e($me['tier']) : '' ?></small></div>
        <a class="dd-item" href="<?= BASE_URL ?>profile.php?u=<?= urlencode($me['username']) ?>">My profile</a>
        <a class="dd-item" href="<?= BASE_URL ?>transactions.php">Transactions</a>
        <a class="dd-item" href="<?= BASE_URL ?>inbox.php">Notifications</a>
        <a class="dd-item danger" href="<?= BASE_URL ?>logout.php">Log out</a>
      </div>
    </div>
  </div>
</div></header>

<aside class="sbw" id="sbw" aria-label="Sidebar">
  <button type="button" class="sb-edge" id="sb-edge" aria-label="Collapse sidebar" aria-expanded="true">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
  </button>
  <nav class="sb" aria-label="Main">
    <?php foreach ($links as [$href, $label, $lkPages, $ic]):
      $on = $href === 'admin/' ? $inAdmin : (!$inAdmin && in_array($page, $lkPages, true)); ?>
      <a class="sb-it<?= $on ? ' on' : '' ?>" href="<?= BASE_URL . $href ?>" title="<?= e($label) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
        <?= $sbIco($ic) ?><span class="lbl"><?= e($label) ?></span>
        <?php if ($href === 'messages.php' && $unreadMsgs): ?><span class="badge"><?= (int)$unreadMsgs ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>

    <div class="sb-sec">
      <button type="button" class="sb-sh" aria-expanded="true" data-sec="subjects"><span>Subjects</span>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg></button>
      <div class="sb-list sb-sub" id="sbl-subjects">
        <?php foreach ($sbSubjects as $sbj): ?>
          <a class="sb-it<?= $sbSlug === $sbj['slug'] ? ' on' : '' ?>" href="<?= BASE_URL ?>forum.php?s=<?= e($sbj['slug']) ?>" title="<?= e($sbj['name']) ?>">
            <span class="sb-av"><?= e(strtoupper(mb_substr($sbj['name'], 0, 1))) ?></span><span class="lbl"><?= e($sbj['name']) ?></span></a>
        <?php endforeach; ?>
        <a class="sb-it" href="<?= BASE_URL ?>forum.php?s=all" title="All subjects"><?= $sbIco('grid') ?><span class="lbl">All subjects</span></a>
      </div>
    </div>

    <div class="sb-sec">
      <button type="button" class="sb-sh" aria-expanded="true" data-sec="account"><span>Account</span>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg></button>
      <div class="sb-list" id="sbl-account">
        <a class="sb-it" href="<?= BASE_URL ?>profile.php?u=<?= urlencode($me['username']) ?>" title="My profile"><?= $sbIco('user') ?><span class="lbl">My profile</span></a>
        <a class="sb-it" href="<?= BASE_URL ?>inbox.php" title="Notifications"><?= $sbIco('bell') ?><span class="lbl">Notifications</span>
          <?php if ($unread): ?><span class="badge"><?= (int)$unread ?></span><?php endif; ?></a>
        <a class="sb-it" href="<?= BASE_URL ?>logout.php" title="Log out"><?= $sbIco('out') ?><span class="lbl">Log out</span></a>
      </div>
    </div>
  </nav>
</aside>
<div class="sb-scrim" id="sb-scrim"></div>

<?php if (empty($hideBubble)): ?>
<div class="mb" id="mb" data-csrf="<?= csrf_token() ?>" data-unread="<?= (int)$unreadMsgs ?>">
  <div class="mb-pop" role="dialog" aria-label="Messages" hidden></div>
  <button type="button" class="mb-toast" hidden></button>
  <button type="button" class="mb-btn" aria-label="Messages" aria-expanded="false">
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>
    <span class="badge mb-badge" <?= $unreadMsgs ? '' : 'hidden' ?>><?= (int)$unreadMsgs ?></span>
  </button>
</div>
<script src="<?= BASE_URL ?>assets/js/bubble.js" defer></script>
<?php endif; ?>
<script src="<?= BASE_URL ?>assets/js/nav.js" defer></script>
<script src="<?= BASE_URL ?>assets/js/countdown.js" defer></script>
