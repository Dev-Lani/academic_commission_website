<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_recent']) && csrf_ok()) {
  unset($_SESSION['recent']);
  header('Location: forum.php'); exit;
}

const PER_PAGE = 15;
$sorts = ['top' => 'Top', 'new' => 'New', 'urgent' => 'Urgent', 'offers' => 'Most offers', 'none' => 'No offers yet'];
$g = fn($k) => is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : '';

$sort   = array_key_exists($g('sort'), $sorts) ? $g('sort') : 'top';
$view   = $g('view') === 'compact' ? 'compact' : 'cards';
$qtext  = mb_substr($g('q'), 0, 80);
$type   = in_array($g('type'), post_types(), true) ? $g('type') : '';
$budget = in_array($g('budget'), ['low', 'mid', 'high'], true) ? $g('budget') : '';
$due    = in_array($g('due'), ['48h', 'week'], true) ? $g('due') : '';
$slug   = $g('s');
$subject = null;

if ($slug !== '' && $slug !== 'all') {
  $q = $pdo->prepare('SELECT * FROM subjects WHERE slug=?');
  $q->execute([$slug]);
  $subject = $q->fetch();
  if (!$subject) { header('Location: forum.php'); exit; }
}

$subjects = cache_remember('forum_subjects', 30, fn() => $pdo->query("SELECT s.name, s.slug, COUNT(p.id) AS n
  FROM subjects s LEFT JOIN posts p ON p.subject_id=s.id AND p.status='open'
  GROUP BY s.id ORDER BY s.name")->fetchAll());
$totalOpen = array_sum(array_column($subjects, 'n'));

$soon = date('Y-m-d H:i:s', time() + 172800); // 48 hours
$now  = date('Y-m-d H:i:s');
$urgentCount = cache_remember('forum_urgent', 30, function () use ($pdo, $now, $soon) {
  $c = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE status='open' AND deadline > ? AND deadline <= ?");
  $c->execute([$now, $soon]);
  return (int)$c->fetchColumn();
});

/* ---------- right rail (commissioners) ---------- */
$recent = []; $urgentList = [];
if ($me['role'] === 'commissioner') {
  if (!empty($_SESSION['recent'])) {
    $ids = array_values(array_unique(array_map('intval', $_SESSION['recent'])));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("SELECT p.id, p.title, p.budget_min, p.budget_max, p.deadline,
        (SELECT COUNT(*) FROM offers o WHERE o.post_id=p.id) AS n
      FROM posts p WHERE p.status='open' AND p.id IN ($ph)");
    $q->execute($ids);
    $byId = array_column($q->fetchAll(), null, 'id');
    foreach ($ids as $i) if (isset($byId[$i])) $recent[] = $byId[$i]; // pinakabago muna
  }
  $urgentList = cache_remember('forum_urgent_list', 30, function () use ($pdo, $now, $soon) {
    $c = $pdo->prepare("SELECT p.id, p.title, p.budget_min, p.budget_max, p.deadline,
        (SELECT COUNT(*) FROM offers o WHERE o.post_id=p.id) AS n
      FROM posts p WHERE p.status='open' AND p.deadline > ? AND p.deadline <= ?
      ORDER BY p.deadline ASC LIMIT 3");
    $c->execute([$now, $soon]);
    return $c->fetchAll();
  });
}
$showRail = $recent || $urgentList;

/* ---------- filters ---------- */
$where = ["p.status='open'"]; $params = [];
if ($subject) { $where[] = 'p.subject_id=:sid'; $params[':sid'] = $subject['id']; }
if ($qtext !== '') {
  $like = '%' . addcslashes($qtext, '%_\\') . '%';
  $where[] = '(p.title LIKE :q1 OR p.description LIKE :q2)';
  $params[':q1'] = $like; $params[':q2'] = $like;
}
if ($type !== '') { $where[] = 'p.type=:type'; $params[':type'] = $type; }
if ($budget === 'low')  $where[] = 'p.budget_min < 500';
if ($budget === 'mid')  $where[] = 'p.budget_min BETWEEN 500 AND 1499';
if ($budget === 'high') $where[] = 'p.budget_min >= 1500';
if ($due !== '') {
  $where[] = 'p.deadline > :dnow AND p.deadline <= :dend';
  $params[':dnow'] = $now;
  $params[':dend'] = $due === '48h' ? $soon : date('Y-m-d H:i:s', time() + 7 * 86400);
}
if ($sort === 'urgent') {
  $where[] = 'p.deadline > :unow AND p.deadline <= :uend';
  $params[':unow'] = $now; $params[':uend'] = $soon;
}
if ($sort === 'none') $where[] = 'NOT EXISTS (SELECT 1 FROM offers x WHERE x.post_id=p.id)';
$whereSql = implode(' AND ', $where);
$order = ['top' => 'p.score DESC, p.created_at DESC', 'new' => 'p.created_at DESC', 'urgent' => 'p.deadline ASC',
          'offers' => 'offer_count DESC, p.created_at DESC', 'none' => 'p.created_at DESC'][$sort];

$c = $pdo->prepare("SELECT COUNT(*) FROM posts p WHERE $whereSql");
$c->execute($params);
$total = (int)$c->fetchColumn();
$pages = max(1, (int)ceil($total / PER_PAGE));
$page  = min(max(1, (int)$g('page')), $pages);
$off   = ($page - 1) * PER_PAGE;

$q = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM offers o WHERE o.post_id=p.id) AS offer_count,
    u.username, u.tier, u.is_verified, s.name AS subject, v.value AS my_vote
  FROM posts p
  JOIN users u ON u.id=p.user_id
  JOIN subjects s ON s.id=p.subject_id
  LEFT JOIN votes v ON v.post_id=p.id AND v.user_id=:me
  WHERE $whereSql ORDER BY $order LIMIT " . PER_PAGE . " OFFSET $off");
$q->execute($params + [':me' => $me['id']]);
$posts = $q->fetchAll();

/* ---------- urls ---------- */
$cur = ['s' => $subject ? $slug : '', 'q' => $qtext, 'type' => $type, 'budget' => $budget, 'due' => $due,
        'sort' => $sort === 'top' ? '' : $sort, 'view' => $view === 'cards' ? '' : $view, 'page' => ''];
$url = function (array $o = []) use ($cur) {
  $a = array_filter(array_merge($cur, $o), fn($v) => $v !== '' && $v !== null);
  $qs = http_build_query($a, '', '&', PHP_QUERY_RFC3986);
  return 'forum.php' . ($qs !== '' ? '?' . $qs : '');
};
$here = $url(['page' => $page > 1 ? $page : '']);   // vote.php sends the user back here
$filtered = $qtext !== '' || $type !== '' || $budget !== '' || $due !== '';

function time_ago($ts){
  $d = time() - strtotime($ts);
  if ($d < 60)        return 'just now';
  if ($d < 3600)      return floor($d / 60) . 'm ago';
  if ($d < 86400)     return floor($d / 3600) . 'h ago';
  if ($d < 7 * 86400) return floor($d / 86400) . 'd ago';
  return date('M j', strtotime($ts));
}
function hidden_fields($vals){
  foreach ($vals as $k => $v) if ($v !== '') echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
}
// [css class, text] para sa gilid ng bawat row sa right rail
function rail_meta($r){
  $left = strtotime($r['deadline']) - time();
  if ($left > 0 && $left <= 172800) {
    $t = $left < 3600 ? floor($left / 60) . 'm left' : ($left < 86400 ? floor($left / 3600) . 'h left' : floor($left / 86400) . 'd left');
    return ['hot', $t];
  }
  return [$r['n'] ? 'ok' : '', $r['n'] ? plural((int)$r['n'], 'offer', 'offers') : 'No offers'];
}
function rail_rows($rows){
  foreach ($rows as $r): [$cls, $txt] = rail_meta($r); ?>
    <a class="rail-row" href="post.php?id=<?= (int)$r['id'] ?>"><b><?= e($r['title']) ?></b>
      <small><span><?= e(money($r['budget_min']) . ' – ' . money($r['budget_max'])) ?></span><span class="<?= $cls ?>"><?= e($txt) ?></span></small></a>
  <?php endforeach;
}
?>
<!doctype html>
<html lang="en"><head><title>Forum · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/forum.css"></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">

  <div class="f-top">
    <h1><?= $subject ? e($subject['name']) : 'Forum' ?></h1>
    <form class="f-search" method="get" action="forum.php" role="search">
      <?php hidden_fields(['s' => $cur['s'], 'type' => $type, 'budget' => $budget, 'due' => $due, 'sort' => $cur['sort'], 'view' => $cur['view']]); ?>
      <input type="text" name="q" value="<?= e($qtext) ?>" maxlength="80" placeholder="Search requests or keywords" aria-label="Search requests">
      <button class="btn sm">Search</button>
    </form>
    <?php if ($me['role'] === 'client'): ?><a class="btn" href="create_post.php<?= $subject ? '?s=' . e($slug) : '' ?>">+ New request</a><?php endif; ?>
  </div>

  <nav class="subj-row" aria-label="Subjects">
    <a class="fcl<?= !$subject ? ' on' : '' ?>" href="<?= e($url(['s' => ''])) ?>">All <i><?= (int)$totalOpen ?></i></a>
    <?php foreach ($subjects as $s): ?>
      <a class="fcl<?= $subject && $slug === $s['slug'] ? ' on' : '' ?>" href="<?= e($url(['s' => $s['slug']])) ?>"><?= e($s['name']) ?> <i><?= (int)$s['n'] ?></i></a>
    <?php endforeach; ?>
  </nav>

  <div class="fl<?= $showRail ? ' has-rail' : '' ?>">
    <div>
      <?php if ($urgentCount && $sort !== 'urgent'): ?>
        <div class="f-urgent"><b><?= e(plural($urgentCount, 'urgent request', 'urgent requests')) ?></b>
          <span class="muted">due within 48 hours. Early offers win.</span>
          <a href="<?= e($url(['sort' => 'urgent'])) ?>">Show urgent</a></div>
      <?php endif; ?>

      <div class="f-bar">
        <div class="grp" role="group" aria-label="Sort">
          <?php foreach ($sorts as $k => $label): ?>
            <a class="fcl<?= $sort === $k ? ' on' : '' ?>" href="<?= e($url(['sort' => $k === 'top' ? '' : $k])) ?>"><?= e($label) ?></a>
          <?php endforeach; ?>
        </div>
        <div class="grp">
          <span class="muted"><?= e(plural($total, 'request', 'requests')) ?></span>
          <a class="fcl<?= $view === 'cards' ? ' on' : '' ?>" href="<?= e($url(['view' => ''])) ?>">Cards</a>
          <a class="fcl<?= $view === 'compact' ? ' on' : '' ?>" href="<?= e($url(['view' => 'compact'])) ?>">Compact</a>
        </div>
      </div>

      <?php if (!$posts): ?>
        <section class="panel"><p class="empty"><?= $filtered ? 'No requests match your filters.' : 'No open requests here yet.' ?>
          <?= $filtered ? '<a href="' . e($url(['q' => '', 'type' => '', 'budget' => '', 'due' => ''])) . '">Clear filters</a>'
                        : ($me['role'] === 'client' ? 'Be the first to post one.' : 'Check back soon or browse another subject.') ?></p></section>
      <?php endif; ?>

      <div class="feed<?= $view === 'compact' ? ' compact' : '' ?>">
      <?php foreach ($posts as $p): $urgent = is_urgent($p); ?>
        <article class="rq<?= $urgent ? ' is-urgent' : '' ?>">
          <div class="rq-vote"><?= vote_box($p, $me, $here) ?></div>
          <div class="rq-main">
            <div class="rq-kicker">
              <span><?= e($p['subject']) ?></span><span aria-hidden="true">·</span><span><?= e($p['type']) ?></span>
              <span class="age">· <?= e(time_ago($p['created_at'])) ?></span>
              <?php if ($urgent): ?><span class="tag urgent" data-prefix="Urgent: " data-deadline="<?= e(date('c', strtotime($p['deadline']))) ?>">Urgent</span><?php endif; ?>
            </div>
            <h3><a href="post.php?id=<?= (int)$p['id'] ?>"><?= e($p['title']) ?></a></h3>
            <p class="rq-excerpt"><?= e(mb_strimwidth(preg_replace('/\s+/', ' ', $p['description']), 0, 160, '…')) ?></p>
            <div class="rq-foot">
              <span class="pill<?= $p['offer_count'] ? ' ok' : '' ?>"><?= $p['offer_count'] ? e(plural((int)$p['offer_count'], 'offer', 'offers')) : 'No offers yet' ?></span>
              <span><?= (int)$p['pages'] ?> <?= $p['pages'] == 1 ? 'page' : 'pages' ?></span>
              <span>by <b><?= e($p['username']) ?></b></span>
              <span class="chip"><?= e($p['tier']) ?></span>
              <?php if ($p['is_verified']): ?><span class="pill ok">Verified</span><?php endif; ?>
            </div>
          </div>
          <div class="rq-side">
            <div class="rq-budget"><small>Budget</small><?= money($p['budget_min']) ?> – <?= money($p['budget_max']) ?></div>
            <div class="rq-due<?= $urgent ? ' hot' : '' ?>"><small>Due</small><?= e(date('M j, g:i A', strtotime($p['deadline']))) ?></div>
            <a class="btn ghost" href="post.php?id=<?= (int)$p['id'] ?>"><?= $me['role'] === 'commissioner' ? 'View &amp; offer' : 'View' ?></a>
          </div>
        </article>
      <?php endforeach; ?>
      </div>

      <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="Pages">
        <?php if ($page > 1): ?><a class="fcl" href="<?= e($url(['page' => $page - 1 > 1 ? $page - 1 : ''])) ?>">‹ Prev</a><?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
          <a class="fcl<?= $i === $page ? ' on' : '' ?>" href="<?= e($url(['page' => $i > 1 ? $i : ''])) ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><a class="fcl" href="<?= e($url(['page' => $page + 1])) ?>">Next ›</a><?php endif; ?>
      </nav>
      <?php endif; ?>

      <section class="panel panel-pad" style="margin-top:.75rem">
        <h2>How it works</h2>
        <p class="muted" style="margin:0;font-size:.9rem">Clients post requests. Commissioners reply with private offers. Payment stays in escrow until you approve the work.</p>
      </section>
    </div>

    <?php if ($showRail): ?>
    <aside class="f-rail" aria-label="Shortcuts">
      <?php if ($recent): ?>
      <section class="rail-card">
        <h2>Recently viewed
          <form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <button class="rail-clear" name="clear_recent" value="1">Clear</button></form></h2>
        <?php rail_rows($recent); ?>
      </section>
      <?php endif; ?>
      <?php if ($urgentList): ?>
      <section class="rail-card">
        <h2>Urgent now <a href="<?= e($url(['sort' => 'urgent'])) ?>">See all</a></h2>
        <?php rail_rows($urgentList); ?>
      </section>
      <?php endif; ?>
    </aside>
    <?php endif; ?>

    <aside class="f-side">
      <form class="panel" method="get" action="forum.php" onchange="this.submit()">
        <h2>Filters</h2>
        <?php hidden_fields(['s' => $cur['s'], 'q' => $qtext, 'sort' => $cur['sort'], 'view' => $cur['view']]); ?>
        <label class="lbl" for="f-type">Type of work</label>
        <select id="f-type" name="type">
          <option value="">Any type</option>
          <?php foreach (post_types() as $t): ?><option<?= $type === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
        </select>
        <span class="lbl">Budget (minimum)</span>
        <div class="f-chips">
          <?php foreach (['' => 'Any', 'low' => 'Under ₱500', 'mid' => '₱500 – ₱1,500', 'high' => '₱1,500+'] as $k => $label): ?>
            <label class="fc"><input type="radio" name="budget" value="<?= $k ?>"<?= $budget === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div>
        <span class="lbl">Deadline</span>
        <div class="f-chips">
          <?php foreach (['' => 'Any', '48h' => '48 hours', 'week' => 'This week'] as $k => $label): ?>
            <label class="fc"><input type="radio" name="due" value="<?= $k ?>"<?= $due === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div>
        <?php if ($filtered): ?><a href="<?= e($url(['q' => '', 'type' => '', 'budget' => '', 'due' => ''])) ?>">Clear filters</a><?php endif; ?>
        <noscript><button class="btn sm">Apply</button></noscript>
      </form>
    </aside>
  </div>
</main>
</body></html>
