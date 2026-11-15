<?php
// The commissioner's saved requests. Open ones come first, nearest deadline on top.
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();
if ($me['role'] !== 'commissioner') { header('Location: forum.php'); exit; }

$q = $pdo->prepare("SELECT p.id, p.title, p.type, p.pages, p.budget_min, p.budget_max, p.deadline, p.status,
    s.name AS subject,
    (SELECT COUNT(*) FROM offers o WHERE o.post_id=p.id) AS offer_count,
    (SELECT COUNT(*) FROM offers o WHERE o.post_id=p.id AND o.user_id=?) AS my_offer
  FROM bookmarks b
  JOIN posts p ON p.id=b.post_id
  JOIN subjects s ON s.id=p.subject_id
  WHERE b.user_id=?
  ORDER BY (p.status='open') DESC, p.deadline ASC");
$q->execute([$me['id'], $me['id']]);
$rows = $q->fetchAll();
?>
<!doctype html>
<html lang="en"><head><title>Saved requests · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">
  <div class="page-head">
    <h1>Saved requests</h1>
    <p><?= e(plural(count($rows), 'request', 'requests')) ?> saved. Come back here when you are ready to send an offer.</p>
  </div>

  <?php if (!$rows): ?>
    <section class="panel"><p class="empty">Nothing saved yet. Tap ☆ Save on any request in the <a href="forum.php?s=all">forum</a> and it will show up here.</p></section>
  <?php else: ?>
  <div class="sv-list">
    <?php foreach ($rows as $r): $isOpen = $r['status'] === 'open'; ?>
    <article class="sv-card<?= $isOpen ? '' : ' closed' ?>">
      <div class="sv-main">
        <div class="sv-kicker">
          <span><?= e($r['subject']) ?></span><span aria-hidden="true">·</span><span><?= e($r['type']) ?></span>
          <?php if (task_kind($r['type']) === 'general'): ?><span class="kchip">Everyday task</span><?php endif; ?>
        </div>
        <h3><a href="post.php?id=<?= (int)$r['id'] ?>"><?= e($r['title']) ?></a></h3>
        <div class="sv-meta">
          <span><?= e(money($r['budget_min']) . ' – ' . money($r['budget_max'])) ?></span>
          <span><?= e(size_label($r)) ?></span>
          <span class="<?= is_urgent($r) ? 'hot' : '' ?>">Due <?= e(date('M j, g:i A', strtotime($r['deadline']))) ?></span>
        </div>
      </div>
      <div class="sv-side">
        <?php if (!$isOpen): ?><span class="tag">No longer open</span>
        <?php elseif ($r['my_offer']): ?><span class="pill ok">You sent an offer</span>
        <?php else: ?><span class="pill<?= $r['offer_count'] ? ' ok' : '' ?>"><?= $r['offer_count'] ? e(plural((int)$r['offer_count'], 'offer', 'offers')) : 'No offers yet' ?></span><?php endif; ?>
        <a class="btn ghost" href="post.php?id=<?= (int)$r['id'] ?>"><?= $isOpen && !$r['my_offer'] ? 'View &amp; offer' : 'View' ?></a>
        <?= save_button($r['id'], true, 'saved.php') ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</main>
</body></html>
