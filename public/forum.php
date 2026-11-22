<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_recent']) && csrf_ok()) {
  unset($_SESSION['recent']);
  header('Location: forum.php'); exit;
}

const PER_PAGE = 15;
$sorts        = ['top' => 'Top', 'new' => 'New', 'urgent' => 'Urgent first', 'offers' => 'Most offers'];
$offerLabels  = ['none' => 'No offers yet', 'has' => 'Has offers', '3plus' => '3 or more offers'];
$dueLabels    = ['48h' => 'Urgent (48 hours)', 'tomorrow' => 'Due by tomorrow', 'week' => 'Due this week'];
$g = fn($k) => is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : '';

// "2026-11-23" -> DateTimeImmutable, or null if it is not a real date
function forum_date($s){
  $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
  return $d && $d->format('Y-m-d') === $s ? $d : null;
}

$sort   = array_key_exists($g('sort'), $sorts) ? $g('sort') : 'top';
$view   = $g('view') === 'compact' ? 'compact' : 'cards';
$qtext  = mb_substr($g('q'), 0, 80);
$type   = in_array($g('type'), post_types(), true) ? $g('type') : '';
$kind   = in_array($g('kind'), ['academic', 'general'], true) ? $g('kind') : '';
$offers = array_key_exists($g('offers'), $offerLabels) ? $g('offers') : '';
$due    = array_key_exists($g('due'), $dueLabels) ? $g('due') : '';
$slug   = $g('s');
$subject = null;

// Deadline range: starts today at the earliest, 30 days at most
$from = $to = null;
$f0 = forum_date($g('from')); $t0 = forum_date($g('to'));
if ($f0 && $t0) {
  $today = new DateTimeImmutable('today');
  if ($f0 < $today) $f0 = $today;
  if ($t0 < $f0) $t0 = $f0;
  $cap = $f0->modify('+30 days');
  if ($t0 > $cap) $t0 = $cap;
  $from = $f0; $to = $t0;
  $due = ''; // a date range replaces the quick due filter
}

if ($slug !== '' && $slug !== 'all') {
  $q = $pdo->prepare('SELECT * FROM subjects WHERE slug=?');
  $q->execute([$slug]);
  $subject = $q->fetch();
  if (!$subject) { header('Location: forum.php'); exit; }
}

$subjects = cache_remember('forum_subjects', 30, fn() => $pdo->query("SELECT s.name, s.slug, COUNT(p.id) AS n
  FROM subjects s LEFT JOIN posts p ON p.subject_id=s.id AND p.status='open'
  GROUP BY s.id ORDER BY s.name")->fetchAll());
$totalOpen  = array_sum(array_column($subjects, 'n'));
$typeCounts = cache_remember('forum_type_counts', 30, fn() => $pdo->query("SELECT type, COUNT(*) AS n FROM posts WHERE status='open' GROUP BY type")->fetchAll(PDO::FETCH_KEY_PAIR));

$soon = date('Y-m-d H:i:s', time() + 172800); // 48 hours
$now  = date('Y-m-d H:i:s');

/* ---------- right rail (commissioners) ---------- */
$recent = []; $urgentList = [];
if ($me['role'] === 'commissioner') {
  if (!empty($_SESSION['recent'])) {
    $ids = array_values(array_unique(array_map('intval', $_SESSION['recent'])));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("SELECT p.id, p.title, p.deadline,
        (SELECT COUNT(*) FROM offers o WHERE o.post_id=p.id) AS n
      FROM posts p WHERE p.status='open' AND p.id IN ($ph)");
    $q->execute($ids);
    $byId = array_column($q->fetchAll(), null, 'id');
    foreach ($ids as $i) if (isset($byId[$i])) $recent[] = $byId[$i]; // pinakabago muna
  }
  $urgentList = cache_remember('forum_urgent_list', 30, function () use ($pdo, $now, $soon) {
    $c = $pdo->prepare("SELECT p.id, p.title, p.deadline,
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
if ($kind !== '') { // school work vs everyday tasks: match every type in that group
  $ph = [];
  foreach (post_type_groups()[$kind]['types'] as $i => $t) { $ph[] = ':k' . $i; $params[':k' . $i] = $t; }
  $where[] = 'p.type IN (' . implode(',', $ph) . ')';
}
if ($offers === 'none')  $where[] = 'NOT EXISTS (SELECT 1 FROM offers x WHERE x.post_id=p.id)';
if ($offers === 'has')   $where[] = 'EXISTS (SELECT 1 FROM offers x WHERE x.post_id=p.id)';
if ($offers === '3plus') $where[] = '(SELECT COUNT(*) FROM offers x WHERE x.post_id=p.id) >= 3';
if ($from) {
  $where[] = 'p.deadline > :dnow AND p.deadline >= :df AND p.deadline <= :dt';
  $params[':dnow'] = $now;
  $params[':df']   = $from->format('Y-m-d') . ' 00:00:00';
  $params[':dt']   = $to->format('Y-m-d') . ' 23:59:59';
} elseif ($due !== '') {
  $where[] = 'p.deadline > :dnow AND p.deadline <= :dend';
  $params[':dnow'] = $now;
  $params[':dend'] = ['48h' => $soon, 'tomorrow' => date('Y-m-d 23:59:59', strtotime('+1 day')),
                      'week' => date('Y-m-d H:i:s', time() + 7 * 86400)][$due];
}
if ($sort === 'urgent') { $where[] = 'p.deadline > :unow'; $params[':unow'] = $now; }
$whereSql = implode(' AND ', $where);
$order = ['top' => 'p.score DESC, p.created_at DESC', 'new' => 'p.created_at DESC', 'urgent' => 'p.deadline ASC',
          'offers' => 'offer_count DESC, p.created_at DESC'][$sort];

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
$saved = $me['role'] === 'commissioner' ? saved_map($me['id'], array_column($posts, 'id')) : []; // ids this commissioner saved

/* ---------- urls ---------- */
$cur = ['s' => $subject ? $slug : '', 'q' => $qtext, 'type' => $type, 'kind' => $kind,
        'offers' => $offers, 'due' => $due, 'from' => $from ? $from->format('Y-m-d') : '', 'to' => $to ? $to->format('Y-m-d') : '',
        'sort' => $sort === 'top' ? '' : $sort, 'view' => $view === 'cards' ? '' : $view, 'page' => ''];
$url = function (array $o = []) use ($cur) {
  $a = array_filter(array_merge($cur, $o), fn($v) => $v !== '' && $v !== null);
  $qs = http_build_query($a, '', '&', PHP_QUERY_RFC3986);
  return 'forum.php' . ($qs !== '' ? '?' . $qs : '');
};
$here = $url(['page' => $page > 1 ? $page : '']);   // vote.php and bookmark.php send the user back here
$clearAll = $url(['q' => '', 'type' => '', 'offers' => '', 'due' => '', 'from' => '', 'to' => '']);


$rangeLabel = $from ? ($from == $to ? $from->format('M j') : $from->format('M j') . ' – ' . $to->format('M j')) : '';
$tags = [];
if ($qtext !== '')            $tags[] = ['“' . $qtext . '”', $url(['q' => ''])];
if ($type !== '')             $tags[] = [$type, $url(['type' => ''])];
if ($offers !== '')           $tags[] = [$offerLabels[$offers], $url(['offers' => ''])];
if ($from)                    $tags[] = ['Due ' . $rangeLabel, $url(['from' => '', 'to' => ''])];
elseif ($due !== '')          $tags[] = [$dueLabels[$due], $url(['due' => ''])];
$filtered = (bool)$tags;

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
      <small><span><?= e('Due ' . date('M j', strtotime($r['deadline']))) ?></span><span class="<?= $cls ?>"><?= e($txt) ?></span></small></a>
  <?php endforeach;
}

// small inline icons for the filter bar
$ic = [
  'cal'    => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/>',
  'type'   => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1"/>',
  'budget' => '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
  'offers' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
  'sort'   => '<path d="M7 4v16M7 20l-3-3M7 20l3-3M17 20V4M17 4l-3 3M17 4l3 3"/>',
  'grid'   => '<rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/>',
  'list'   => '<path d="M4 6h16M4 12h16M4 18h16"/>',
  'x'      => '<path d="M6 6l12 12M18 6 6 18"/>',
  'down'   => '<path d="m6 9 6 6 6-6"/>',
  'prev'   => '<path d="m15 5-7 7 7 7"/>',
  'next'   => '<path d="m9 5 7 7-7 7"/>',
];
$svg = fn($n, $sz = 16, $sw = 1.8) => '<svg width="' . $sz . '" height="' . $sz . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' . $sw . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $ic[$n] . '</svg>';
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
      <?php hidden_fields(['s' => $cur['s'], 'type' => $type, 'kind' => $kind,
                           'offers' => $offers, 'due' => $due, 'from' => $cur['from'], 'to' => $cur['to'], 'sort' => $cur['sort'], 'view' => $cur['view']]); ?>
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

  <!-- ===== One filter bar ===== -->
  <form class="ff-bar" id="ff" method="get" action="forum.php" aria-label="Filters">
    <?php hidden_fields(['s' => $cur['s'], 'q' => $qtext, 'kind' => $kind, 'view' => $cur['view']]); ?>
    <input type="hidden" name="due"  id="ff-due"  value="<?= e($due) ?>">
    <input type="hidden" name="from" id="ff-from" value="<?= e($cur['from']) ?>">
    <input type="hidden" name="to"   id="ff-to"   value="<?= e($cur['to']) ?>">

    <div class="ff-row">
      <div class="ff-seg" role="group" aria-label="Kind of task">
        <?php foreach (['' => 'All tasks', 'academic' => 'School work', 'general' => 'Everyday tasks'] as $k => $label): ?>
          <a class="<?= $kind === $k ? 'on' : '' ?>" href="<?= e($url(['kind' => $k])) ?>"<?= $kind === $k ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
      <span class="ff-sep" aria-hidden="true"></span>
      <a class="ff-chip hot<?= $due === '48h' ? ' on' : '' ?>" href="<?= e($url(['due' => $due === '48h' ? '' : '48h', 'from' => '', 'to' => ''])) ?>">Urgent <i>due in 48h</i></a>
      <a class="ff-chip<?= $due === 'tomorrow' ? ' on' : '' ?>" href="<?= e($url(['due' => $due === 'tomorrow' ? '' : 'tomorrow', 'from' => '', 'to' => ''])) ?>">Tomorrow</a>
      <a class="ff-chip<?= $due === 'week' ? ' on' : '' ?>" href="<?= e($url(['due' => $due === 'week' ? '' : 'week', 'from' => '', 'to' => ''])) ?>">This week</a>

      <details class="ff-dd">
        <summary class="ff-chip<?= $from ? ' rng' : '' ?>"><?= $svg('cal') ?><?= $from ? e($rangeLabel) : 'Pick dates' ?></summary>
        <div class="ff-mn wd">
          <div class="ff-ph">
            <button type="button" class="ff-nv" id="cal-prev" aria-label="Previous month"><?= $svg('prev', 16, 2) ?></button>
            <b id="cal-title"></b>
            <button type="button" class="ff-nv" id="cal-next" aria-label="Next month"><?= $svg('next', 16, 2) ?></button>
          </div>
          <div class="ff-cal" id="cal" data-today="<?= date('Y-m-d') ?>"></div>
          <div class="ff-pf">
            <p id="cal-txt">Pick a start date.</p>
            <div><a class="ff-gh" href="<?= e($url(['from' => '', 'to' => ''])) ?>">Clear</a><button class="ff-ap" id="cal-go" disabled>Show requests</button></div>
          </div>
        </div>
      </details>
    </div>

    <div class="ff-row">
      <details class="ff-dd">
        <summary class="ff-fb<?= $type !== '' ? ' on' : '' ?>"><?= $svg('type') ?><span>Type</span><em><?= e($type !== '' ? $type : 'Any') ?></em><?= $svg('down', 14, 2.2) ?></summary>
        <div class="ff-mn">
          <input class="ff-srch" type="search" id="type-q" placeholder="Search types" aria-label="Search types" autocomplete="off">
          <div class="ff-sc">
            <div class="ff-ls"><label class="ff-o"><input type="radio" name="type" value=""<?= $type === '' ? ' checked' : '' ?>><span>Any type</span><small><?= (int)$totalOpen ?></small></label></div>
            <?php foreach (post_type_groups() as $grp): ?>
              <div class="ff-grp" data-group>
                <p class="ff-gl"><?= e($grp['label']) ?></p>
                <div class="ff-ls">
                  <?php foreach ($grp['types'] as $t): ?>
                    <label class="ff-o" data-type="<?= e($t) ?>"><input type="radio" name="type" value="<?= e($t) ?>"<?= $type === $t ? ' checked' : '' ?>><span><?= e($t) ?></span><small><?= (int)($typeCounts[$t] ?? 0) ?></small></label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="ff-fo"><a class="ff-rs" href="<?= e($url(['type' => ''])) ?>">Reset</a><button class="ff-ap">Apply</button></div>
        </div>
      </details>

      <details class="ff-dd">
        <summary class="ff-fb<?= $offers !== '' ? ' on' : '' ?>"><?= $svg('offers') ?><span>Offers</span><em><?= e($offerLabels[$offers] ?? 'Any') ?></em><?= $svg('down', 14, 2.2) ?></summary>
        <div class="ff-mn">
          <div class="ff-ls ff-pad">
            <?php foreach (['' => 'Any'] + $offerLabels as $k => $label): ?>
              <label class="ff-o"><input type="radio" name="offers" value="<?= e($k) ?>"<?= $offers === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div>
          <div class="ff-fo"><a class="ff-rs" href="<?= e($url(['offers' => ''])) ?>">Reset</a><button class="ff-ap">Apply</button></div>
        </div>
      </details>

      <span class="ff-push"></span>

      <details class="ff-dd">
        <summary class="ff-fb"><?= $svg('sort') ?><span>Sort</span><em><?= e($sorts[$sort]) ?></em><?= $svg('down', 14, 2.2) ?></summary>
        <div class="ff-mn r ff-narrow">
          <div class="ff-ls ff-pad">
            <?php foreach ($sorts as $k => $label): ?>
              <label class="ff-o"><input type="radio" name="sort" value="<?= $k === 'top' ? '' : e($k) ?>"<?= $sort === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
      </details>

      <div class="ff-seg" role="group" aria-label="View">
        <a class="ic<?= $view === 'cards' ? ' on' : '' ?>" href="<?= e($url(['view' => ''])) ?>" aria-label="Cards" title="Cards"><?= $svg('grid') ?></a>
        <a class="ic<?= $view === 'compact' ? ' on' : '' ?>" href="<?= e($url(['view' => 'compact'])) ?>" aria-label="Compact list" title="Compact list"><?= $svg('list') ?></a>
      </div>
    </div>

    <div class="ff-sum">
      <b><?= e(plural($total, 'request', 'requests')) ?></b>
      <?php foreach ($tags as [$lab, $href]): ?>
        <span class="ff-tag"><?= e($lab) ?><a href="<?= e($href) ?>" aria-label="Remove filter: <?= e($lab) ?>"><?= $svg('x', 12, 2.5) ?></a></span>
      <?php endforeach; ?>
      <?php if ($tags): ?><a class="ff-lnk" href="<?= e($clearAll) ?>">Clear all</a><?php endif; ?>
    </div>
  </form>

  <div class="fl<?= $showRail ? ' has-rail' : '' ?>">
    <div>
      <?php if (!$posts): ?>
        <section class="panel"><p class="empty"><?= $filtered ? 'No requests match your filters.' : 'No open requests here yet.' ?>
          <?= $filtered ? '<a href="' . e($clearAll) . '">Clear filters</a>'
                        : ($me['role'] === 'client' ? 'Be the first to post one.' : 'Check back soon or browse another subject.') ?></p></section>
      <?php endif; ?>

      <div class="feed<?= $view === 'compact' ? ' compact' : '' ?>">
      <?php foreach ($posts as $p): $urgent = is_urgent($p); ?>
        <article class="rq<?= $urgent ? ' is-urgent' : '' ?>">
          <div class="rq-vote"><?= vote_box($p, $me, $here) ?></div>
          <div class="rq-main">
            <div class="rq-kicker">
              <span><?= e($p['subject']) ?></span><span aria-hidden="true">·</span><span><?= e($p['type']) ?></span>
              <?php if (task_kind($p['type']) === 'general'): ?><span class="kchip">Everyday task</span><?php endif; ?>
              <span class="age">· <?= e(time_ago($p['created_at'])) ?></span>
              <?php if ($urgent): ?><span class="tag urgent" data-prefix="Urgent: " data-deadline="<?= e(date('c', strtotime($p['deadline']))) ?>">Urgent</span><?php endif; ?>
            </div>
            <h3><a href="post.php?id=<?= (int)$p['id'] ?>"><?= e($p['title']) ?></a></h3>
            <p class="rq-excerpt"><?= e(mb_strimwidth(preg_replace('/\s+/', ' ', $p['description']), 0, 160, '…')) ?></p>
            <div class="rq-foot">
              <span class="pill<?= $p['offer_count'] ? ' ok' : '' ?>"><?= $p['offer_count'] ? e(plural((int)$p['offer_count'], 'offer', 'offers')) : 'No offers yet' ?></span>
              <span><?= e(size_label($p)) ?></span>
              <span>by <b><?= e($p['username']) ?></b></span>
              <span class="chip"><?= e($p['tier']) ?></span>
              <?php if ($p['is_verified']): ?><span class="pill ok">Verified</span><?php endif; ?>
            </div>
          </div>
          <div class="rq-side">
            <div class="rq-due<?= $urgent ? ' hot' : '' ?>"><small>Due</small><?= e(date('M j, g:i A', strtotime($p['deadline']))) ?></div>
            <div class="rq-act">
              <a class="btn ghost" href="post.php?id=<?= (int)$p['id'] ?>"><?= $me['role'] === 'commissioner' ? 'View &amp; offer' : 'View' ?></a>
              <?php if ($me['role'] === 'commissioner') echo save_button($p['id'], isset($saved[(int)$p['id']]), $here); ?>
            </div>
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
        <h2>Urgent now <a href="<?= e($url(['due' => '48h', 'sort' => 'urgent', 'from' => '', 'to' => ''])) ?>">See all</a></h2>
        <?php rail_rows($urgentList); ?>
      </section>
      <?php endif; ?>
    </aside>
    <?php endif; ?>
  </div>
</main>
<script src="<?= BASE_URL ?>assets/js/forum.js" defer></script>
</body></html>