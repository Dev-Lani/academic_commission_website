<?php
// Top bar + floating Messages bubble. Needs $me (from require_login()).
// A page can set $hideBubble = true before including this file (messages.php does).
$unread     = unread_count($me['id']);
$unreadMsgs = messages_unread($me['id']);
$path    = $_SERVER['SCRIPT_NAME'] ?? '';
$page    = basename($path);
$inAdmin = strpos($path, '/admin/') !== false;

$links = [
  ['dashboard.php', 'Dashboard', ['dashboard.php']],
  ['forum.php',     'Forum',     ['forum.php', 'post.php', 'create_post.php']],
  ['orders.php',    'My orders', ['orders.php', 'order.php', 'preview.php']],
];

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
<header class="topnav"><div class="tn">
  <a class="brand" href="<?= BASE_URL ?>dashboard.php">Academic Commission</a>
  <nav class="tn-links" aria-label="Main">
    <?php foreach ($links as [$href, $label, $pages]): $on = !$inAdmin && in_array($page, $pages, true); ?>
      <a href="<?= BASE_URL . $href ?>" class="<?= $on ? 'on' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
    <?php if ($me['role'] === 'admin'): ?><a href="<?= BASE_URL ?>admin/" class="<?= $inAdmin ? 'on' : '' ?>">Admin</a><?php endif; ?>
  </nav>

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
