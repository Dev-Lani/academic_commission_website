<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me  = require_login();
$uid = (int)$me['id'];

$tabs  = ['all' => 'All', 'unread' => 'Unread', 'active' => 'Active', 'closed' => 'Closed'];
$g     = fn($k) => is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : '';
$f     = array_key_exists($g('f'), $tabs) ? $g('f') : 'all';
$qtext = mb_substr($g('q'), 0, 80);
$want  = (int)$g('id');

/* ---------- helpers ---------- */
// Closed = tapos na ang order (completed, declined, expired, cancelled).
function cv_closed($status){ return !in_array($status, ['pending_agree', 'active', 'delivered', 'disputed'], true); }

function cv_match($r, $tab, $q){
  if ($q !== '' && mb_stripos($r['other_name'] . ' ' . $r['title'], $q) === false) return false;
  $closed = cv_closed($r['status']);
  if ($tab === 'unread') return $r['unread'] > 0;
  if ($tab === 'active') return !$closed;
  if ($tab === 'closed') return $closed;
  return !($closed && !$r['msg_cnt']); // All: itago ang tapos na order na walang messages
}
function cv_pick($rows, $tab, $q){ return array_keys(array_filter($rows, fn($r) => cv_match($r, $tab, $q))); }

// [label, css class] ng status pill
function cv_status($r, $uid){
  $isComm = (int)$r['commissioner_id'] === $uid;
  switch ($r['status']) {
    case 'pending_agree': return $isComm ? ['Reply needed', 'rev'] : ['Waiting for reply', 'done'];
    case 'active':        return ['In progress', 'act'];
    case 'delivered':     return $isComm ? ['Awaiting review', 'act'] : ['Review needed', 'rev'];
    case 'disputed':      return ['Under dispute', 'dis'];
    case 'completed':     return $r['rated'] ? ['Completed', 'done'] : ['Rating needed', 'rev'];
    case 'declined':      return ['Declined', 'done'];
    case 'expired':       return ['No response', 'done'];
  }
  return ['Cancelled', 'done'];
}

/* ---------- conversations (isang query, may limit) ---------- */
$q = $pdo->prepare("SELECT ord.id, ord.status, ord.price, ord.escrow_status, ord.fee, ord.agree_by, ord.deliver_by, ord.review_by,
    ord.accepted_at, ord.started_at, ord.delivered_at, ord.completed_at, oo.final_name,
    ord.client_id, ord.commissioner_id,
    p.title, oth.username AS other_name, oth.role AS other_role, oth.tier AS other_tier,
    lm.cnt AS msg_cnt, m.body AS last_body, m.sender_id AS last_sender, m.created_at AS last_at,
    (SELECT COUNT(*) FROM order_messages x WHERE x.order_id=ord.id AND x.sender_id<>:u1
       AND x.id > COALESCE(cr.last_id, 0)) AS unread,
    EXISTS (SELECT 1 FROM ratings r2 WHERE r2.order_id=ord.id AND r2.rater_id=:u2) AS rated
  FROM orders ord
  JOIN posts p ON p.id=ord.post_id
  LEFT JOIN order_outputs oo ON oo.order_id=ord.id
  JOIN users oth ON oth.id = IF(ord.client_id=:u3, ord.commissioner_id, ord.client_id)
  LEFT JOIN (SELECT order_id, MAX(id) AS mid, COUNT(*) AS cnt FROM order_messages
             WHERE order_id IN (SELECT id FROM orders WHERE client_id=:u4 OR commissioner_id=:u5)
             GROUP BY order_id) lm ON lm.order_id=ord.id
  LEFT JOIN order_messages m ON m.id=lm.mid
  LEFT JOIN chat_reads cr ON cr.order_id=ord.id AND cr.user_id=:u6
  WHERE (ord.client_id=:u7 OR ord.commissioner_id=:u8)
    AND (ord.status IN ('active','delivered','disputed','completed') OR lm.cnt IS NOT NULL)
  ORDER BY (unread > 0) DESC, COALESCE(last_at, ord.started_at, ord.accepted_at) DESC
  LIMIT 100");
$q->execute([':u1'=>$uid, ':u2'=>$uid, ':u3'=>$uid, ':u4'=>$uid, ':u5'=>$uid, ':u6'=>$uid, ':u7'=>$uid, ':u8'=>$uid]);
$rows = [];
foreach ($q->fetchAll() as $r) {
  foreach (['id', 'msg_cnt', 'unread', 'rated'] as $k) $r[$k] = (int)$r[$k];
  $rows[$r['id']] = $r;
}

/* ---------- which tab, which chat ---------- */
$ids      = cv_pick($rows, $f, $qtext);
$explicit = $want > 0 && isset($rows[$want]);          // may ?id= sa URL (galing sa list o ibang page)
if ($explicit && !in_array($want, $ids, true)) {        // nakatago sa current tab? ilipat sa tamang tab
  $f = cv_closed($rows[$want]['status']) ? 'closed' : 'all';
  $qtext = '';
  $ids = cv_pick($rows, $f, $qtext);
}
$selId = $explicit ? $want : ($ids[0] ?? 0);            // default: una sa listahan (unread muna)
$sel   = $selId ? $rows[$selId] : null;
$open  = $explicit;                                     // phone: chat lang ang ipakita kapag may napili

// Render ng chat bago ang nav, para mawala agad ang unread badge.
$panel = '';
if ($sel) { $panel = chat_panel($sel, $uid); $rows[$selId]['unread'] = 0; }

$cnt = [];
foreach ($tabs as $k => $_) $cnt[$k] = count(cv_pick($rows, $k, ''));

$url = function (array $o = []) use ($f, $qtext) {
  $a = array_merge(['f' => $f === 'all' ? '' : $f, 'q' => $qtext, 'id' => ''], $o);
  $a = array_filter($a, fn($v) => $v !== '' && $v !== null);
  $qs = http_build_query($a, '', '&', PHP_QUERY_RFC3986);
  return 'messages.php' . ($qs !== '' ? '?' . $qs : '');
};

/* ---------- order context strip ---------- */
$ctx = []; $cta = null; $stTxt = $stCls = ''; $isComm = false;
if ($sel) {
  $isComm = (int)$sel['commissioner_id'] === $uid;
  [$stTxt, $stCls] = cv_status($sel, $uid);
  $price  = (int)$sel['price'];
  $escrow = ['none' => 'Not funded yet', 'held' => 'Held in escrow', 'released' => 'Released', 'refunded' => 'Refunded'][$sel['escrow_status']] ?? '';
  $ord    = 'order.php?id=' . $selId;
  $fmt    = fn($ts) => date('M j, g:i A', strtotime($ts));
  $ctx[]  = ['Price', money($price), null, false];            // [label, text, deadline, show seconds]
  switch ($sel['status']) {
    case 'pending_agree':
      $ctx[] = ['Reply by', $fmt($sel['agree_by']), $sel['agree_by'], true];
      if ($isComm) $cta = ['Confirm order', $ord];
      break;
    case 'active':
      $ctx[] = ['Escrow', $escrow, null, false];
      $ctx[] = ['Due', $fmt($sel['deliver_by']), $sel['deliver_by'], false];
      if ($isComm) $cta = ['Deliver work', $ord];
      break;
    case 'delivered':
      $ctx[] = ['Escrow', $escrow, null, false];
      $ctx[] = ['Review window', $fmt($sel['review_by']), $sel['review_by'], false];
      if (!$isComm) $cta = ['Review work', $ord];
      break;
    case 'disputed':
      $ctx[] = ['Escrow', $escrow, null, false];
      $ctx[] = ['Status', 'Admin review', null, false];
      break;
    case 'completed':
      if ($sel['escrow_status'] === 'released') {
        $fee = (int)$sel['fee'];
        $ctx = [[$isComm ? 'Client paid' : 'You paid', money($price), null, false],
                ['Platform fee', '−' . money($fee), null, false],
                [$isComm ? 'You received' : 'Commissioner got', money($price - $fee), null, false]];
      } else {
        $ctx[] = ['Escrow', $escrow, null, false];
      }
      if (!$sel['rated']) $cta = ['Rate ' . $sel['other_name'], $ord . '#rate'];
      break;
  }
}

/* ---------- order side panel: timeline + final file ---------- */
$opSteps = []; $opFile = null;
if ($sel) {
  $stMap = ['pending_agree' => ['cur', 'todo', 'todo', 'todo'], 'active' => ['done', 'cur', 'todo', 'todo'],
            'delivered' => ['done', 'done', 'cur', 'todo'], 'disputed' => ['done', 'done', 'bad', 'todo'],
            'completed' => ['done', 'done', 'done', 'done']];
  if (isset($stMap[$sel['status']])) {
    $late = $sel['delivered_at'] && strtotime($sel['delivered_at']) > strtotime($sel['deliver_by']);
    foreach ([['Accepted', 'accepted_at'], ['Started', 'started_at'], ['Delivered', 'delivered_at'], ['Completed', 'completed_at']] as $i => [$lab, $col]) {
      $s = $stMap[$sel['status']][$i];
      $d = $sel[$col] ? date('M j, g:i A', strtotime($sel[$col])) : '';
      if ($col === 'delivered_at' && $d !== '') $d .= $late ? ' · late' : ' · on time';
      if ($d === '' && $s === 'todo') $d = 'Pending';
      $opSteps[] = [$lab, $s, $d];
    }
  }
  if ($sel['final_name']) { // same rule as download.php: commissioner anytime, client only after completed
    $opFile = ['name' => $sel['final_name'], 'ok' => $isComm || $sel['status'] === 'completed'];
  }
}

$chatIcon = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>';
$hideBubble = true; // nasa Messages page na tayo, hindi na kailangan ang floating bubble
?>
<!doctype html>
<html lang="en"><head><title>Messages · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/chat.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/messages.css">
<script>try{if(matchMedia('(min-width:821px)').matches&&localStorage.getItem('acw.op')==='1')document.documentElement.classList.add('op-open')}catch(e){}</script></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap cv-wide<?= $open ? ' mc-open' : '' ?>">

  <?php if (!$rows): ?>
    <section class="panel cv-empty">
      <span class="eic"><?= $chatIcon ?></span>
      <h2>Wala ka pang conversation</h2>
      <p>Magbubukas ang chat kapag may commissioner na pumayag sa order mo.</p>
      <?php if ($me['role'] === 'client'): ?><a class="btn" href="create_post.php">Post a request</a>
      <?php elseif ($me['role'] === 'commissioner'): ?><a class="btn" href="forum.php?s=all">Browse requests</a><?php endif; ?>
    </section>
  <?php else: ?>
  <div class="msgs<?= $open ? ' open' : '' ?>">

    <section class="panel convos" aria-label="Conversations">
      <form class="cv-search" method="get" action="messages.php" role="search">
        <?php if ($f !== 'all'): ?><input type="hidden" name="f" value="<?= e($f) ?>"><?php endif; ?>
        <input type="text" name="q" value="<?= e($qtext) ?>" maxlength="80" placeholder="Search name or project" aria-label="Search chats">
      </form>
      <nav class="cv-tabs" aria-label="Filter chats">
        <?php foreach ($tabs as $k => $label): ?>
          <a class="fcl<?= $f === $k ? ' on' : '' ?>" href="<?= e($url(['f' => $k === 'all' ? '' : $k, 'id' => ''])) ?>"<?= $f === $k ? ' aria-current="page"' : '' ?>>
            <?= e($label) ?> <i><?= (int)$cnt[$k] ?></i></a>
        <?php endforeach; ?>
      </nav>

      <?php if (!$ids): ?>
        <p class="cv-none">No chats match.
          <a href="<?= e($url(['f' => '', 'q' => '', 'id' => ''])) ?>">Show all</a></p>
      <?php endif; ?>
      <ul id="convo-list" data-cur="<?= (int)$selId ?>">
        <?php foreach ($ids as $rid): $r = $rows[$rid];
          $on = $rid === $selId; $un = $r['unread'];
          [$stT, $stC] = cv_status($r, $uid);
          $prev = $r['last_body'] !== null
            ? (((int)$r['last_sender'] === $uid ? 'You: ' : '') . mb_strimwidth(str_replace("\n", ' ', $r['last_body']), 0, 70, '…'))
            : 'No messages yet'; ?>
        <li><a class="convo<?= $on ? ' on' : '' ?><?= $un ? ' unread' : '' ?>" data-id="<?= $rid ?>" href="<?= e($url(['id' => $rid])) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
          <span class="cv-av" aria-hidden="true"><?= e(strtoupper(mb_substr($r['other_name'], 0, 1))) ?></span>
          <span class="cv-mid">
            <span class="cv-top"><b><?= e($r['other_name']) ?></b><time class="cv-time"><?= $r['last_at'] ? e(chat_time($r['last_at'])) : '' ?></time></span>
            <span class="cv-title"><?= e($r['title']) ?></span>
            <span class="cv-prev"><?= e($prev) ?></span>
            <span class="cv-st <?= e($stC) ?>"><?= e($stT) ?></span>
          </span>
          <?php if ($un): ?><span class="badge"><?= $un ?></span><?php endif; ?>
        </a></li>
        <?php endforeach; ?>
      </ul>
    </section>

    <div class="panel convo-main">
      <?php if ($sel): ?>
        <div class="cm-head">
          <a class="cm-back" href="<?= e($url(['id' => ''])) ?>" aria-label="Back to chats">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg></a>
          <span class="cv-av" aria-hidden="true"><?= e(strtoupper(mb_substr($sel['other_name'], 0, 1))) ?></span>
          <div class="cm-who">
            <h2><?= e($sel['other_name']) ?></h2>
            <small class="muted"><?= e($sel['title'] . ' · ' . ucfirst($sel['other_role']) . ' · ' . $sel['other_tier']) ?></small>
          </div>
          <span class="cv-st <?= e($stCls) ?>"><?= e($stTxt) ?></span>
          <button type="button" class="btn ghost cm-op" id="op-toggle" aria-controls="op" aria-expanded="false">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16"/></svg>View order</button>
        </div>

        <?php if ($ctx || $cta): ?>
        <div class="cm-ctx">
          <?php foreach ($ctx as [$lab, $txt, $dl, $sec]): ?>
            <div><small><?= e($lab) ?></small><b<?= $dl ? ' data-deadline="' . e(date('c', strtotime($dl))) . '"' . ($sec ? ' data-sec="1"' : '') : '' ?>><?= e($txt) ?></b></div>
          <?php endforeach; ?>
          <?php if ($cta): ?><a class="btn cm-cta" href="<?= e($cta[1]) ?>"><?= e($cta[0]) ?></a><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (chat_open($sel['status'])): ?>
          <p class="cm-warn" role="note">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/></svg>
            <span>Huwag magbayad o magbigay ng contact sa labas ng platform. Escrow lang ang protektado.</span></p>
        <?php endif; ?>

        <?= $panel ?>
      <?php else: ?>
        <div class="cv-empty">
          <span class="eic"><?= $chatIcon ?></span>
          <h2>Pumili ng chat</h2>
          <p>Piliin ang conversation sa kaliwa para makita ang messages at ang status ng order.</p>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($sel): ?>
    <aside class="panel op" id="op" aria-label="Order details">
      <div class="op-hd"><b>Order details</b>
        <button type="button" class="op-x" id="op-close" aria-label="Close order details">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>
      <div class="op-bd">
        <div><p class="op-ttl"><?= e($sel['title']) ?></p>
          <div class="op-sub"><span class="cv-st <?= e($stCls) ?>"><?= e($stTxt) ?></span><span>Order #<?= (int)$selId ?></span></div></div>

        <?php if ($opSteps): ?>
        <div><p class="op-t">Progress</p>
          <ol class="tl">
            <?php foreach ($opSteps as [$lab, $s, $d]): ?>
              <li class="<?= e($s) ?>"><b><?= e($lab) ?><?= $s === 'cur' ? '<span class="sr"> (current step)</span>' : '' ?></b><?= $d !== '' ? '<small>' . e($d) . '</small>' : '' ?></li>
            <?php endforeach; ?>
          </ol></div>
        <?php else: ?>
          <p class="op-note">This order did not go ahead.</p>
        <?php endif; ?>

        <div><p class="op-t">Payment</p>
          <?php $lastI = count($ctx) - 1; $paid = $sel['status'] === 'completed' && $sel['escrow_status'] === 'released';
          foreach ($ctx as $i => [$lab, $txt, $dl, $sec]): ?>
            <div class="kv<?= $paid && $i === $lastI ? ' tot' : '' ?>"><span><?= e($lab) ?></span><b<?= $dl ? ' data-deadline="' . e(date('c', strtotime($dl))) . '"' . ($sec ? ' data-sec="1"' : '') : '' ?>><?= e($txt) ?></b></div>
          <?php endforeach; ?>
          <?php if ($paid): ?><div class="kv"><span>Escrow</span><b>Released</b></div><?php endif; ?>
        </div>

        <?php if ($opFile): ?>
        <div><p class="op-t">Final file</p>
          <?php if ($opFile['ok']): ?>
            <div class="fcard">
              <div class="fc-top"><span class="fc-ic" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/></svg></span>
                <div><b><?= e($opFile['name']) ?></b><small><?= $isComm ? 'The file you delivered' : 'Unlocked after approval' ?></small></div></div>
              <a class="btn" href="download.php?id=<?= (int)$selId ?>">Download</a>
            </div>
          <?php else: ?>
            <div class="fcard lk">
              <div class="fc-top"><span class="fc-ic" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg></span>
                <div><b>Locked</b><small><?= $sel['status'] === 'disputed' ? 'Locked while the dispute is under review' : 'Unlocks when you approve the work' ?></small></div></div>
              <button type="button" class="btn off" disabled>Download</button>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="op-ft">
        <?php if ($cta): ?><a class="btn" href="<?= e($cta[1]) ?>"><?= e($cta[0]) ?></a><?php endif; ?>
        <a class="btn ghost" href="order.php?id=<?= (int)$selId ?>">Open full order page</a>
      </div>
    </aside>
    <?php endif; ?>

  </div>
  <?php endif; ?>
</main>
<?php if ($sel): ?><script src="<?= BASE_URL ?>assets/js/chat.js" defer></script><?php endif; ?>
<?php if ($rows): ?><script src="<?= BASE_URL ?>assets/js/messages.js" defer></script><?php endif; ?>
</body></html>