<?php
// Small shared helpers: escaping, CSRF, formatting, UI snippets.

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Redirect to a path inside /public (works from public/ and public/admin/).
function redirect($to){ header('Location: ' . BASE_URL . $to); exit; }

function csrf_token(){ return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function csrf_ok(){ return hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? ''); }

function here(){
  $qs = $_SERVER['QUERY_STRING'] ?? '';
  return basename($_SERVER['PHP_SELF']) . ($qs !== '' ? '?' . $qs : '');
}

// Types of work, in two groups. "School work" is what the site was built for; "Everyday tasks" are the
// other small jobs people also post here (welcome, but school work stays the main focus).
// Keep every type name at 30 characters or fewer: posts.type is VARCHAR(30).
function post_type_groups(){
  return [
    'academic' => ['label' => 'School work', 'types' => [
      'Essay','Research paper','Thesis chapter','Editing / proofreading','Reflection paper',
      'Case study','Report','Presentation (PPT)','Programming project','Problem set','Other']],
    'general'  => ['label' => 'Everyday tasks', 'types' => [
      'Design / graphics','Tutoring / coaching','Data entry / encoding','Translation',
      'Tech help','Event help','Errand / pickup','Other task']],
  ];
}

// Flat list of every type (used for validation and the forum filter).
function post_types(){
  return array_merge(...array_column(post_type_groups(), 'types'));
}

// 'academic' or 'general', worked out from the type. Posts made before this feature are all academic.
function task_kind($type){
  return in_array($type, post_type_groups()['general']['types'], true) ? 'general' : 'academic';
}

// "5 pages" for school work, "1 item" for everyday tasks.
function size_label($p){
  $n = (int)$p['pages'];
  return task_kind($p['type']) === 'general' ? plural($n, 'item', 'items') : plural($n, 'page', 'pages');
}

function money($n){ return '₱' . number_format((int)$n); }

function is_urgent($p){
  $left = strtotime($p['deadline']) - time();
  return $p['status'] === 'open' && $left > 0 && $left <= 172800; // 48 hours
}

function vote_box($p, $me, $back){
  $own = $p['user_id'] == $me['id'];
  $mv  = (int)($p['my_vote'] ?? 0);
  $t   = csrf_token();
  $h   = '<div class="votes">';
  foreach ([[1,'up','▲','Upvote'], [-1,'down','▼','Downvote']] as [$val,$cls,$ico,$lab]) {
    if ($cls === 'down') $h .= '<span class="score">' . (int)$p['score'] . '</span>';
    $h .= '<form method="post" action="vote.php">'
        . '<input type="hidden" name="csrf" value="' . $t . '">'
        . '<input type="hidden" name="post_id" value="' . (int)$p['id'] . '">'
        . '<input type="hidden" name="value" value="' . $val . '">'
        . '<input type="hidden" name="back" value="' . e($back) . '">'
        . '<button class="vbtn ' . $cls . ($mv === $val ? ' on' : '') . '" aria-label="' . $lab . '"'
        . ($own ? ' disabled title="You can\'t vote on your own request"' : '') . '>' . $ico . '</button></form>';
  }
  return $h . '</div>';
}

function unread_count($uid){
  global $pdo;
  $q = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
  $q->execute([$uid]);
  return (int)$q->fetchColumn();
}

function plural($n, $one, $many){ return $n . ' ' . ($n == 1 ? $one : $many); }

function sample_html($s){
  if (filter_var($s, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $s)) {
    return '<a href="' . e($s) . '" target="_blank" rel="noopener noreferrer">' . e($s) . '</a>';
  }
  return nl2br(e($s));
}

function status_tag($s){
  $m = ['pending_agree'=>['Waiting for commissioner','warn'], 'active'=>['In progress','ok'],
        'declined'=>['Declined','bad'], 'expired'=>['No response','bad'],
        'cancelled'=>['Cancelled','bad'], 'completed'=>['Completed','ok'],
        'delivered'=>['Delivered, awaiting review','warn'], 'disputed'=>['Under dispute','bad']];
  [$t, $c] = $m[$s] ?? [$s, ''];
  return '<span class="tag ' . $c . '">' . e($t) . '</span>';
}

// Progress stepper for an order row. When the row carries accepted_at, each reached step also shows its date.
function order_timeline($o){
  $map = ['pending_agree' => ['cur', '', '', ''], 'active' => ['done', 'cur', '', ''],
          'delivered' => ['done', 'done', 'cur', ''], 'disputed' => ['done', 'done', 'bad', ''],
          'completed' => ['done', 'done', 'done', 'done']];
  $st = $o['status'] ?? '';
  if (!isset($map[$st])) return '';
  $note = ['cur' => ' <span class="o-sr">(current step)</span>', 'bad' => ' <span class="o-sr">(disputed)</span>',
           'done' => ' <span class="o-sr">(done)</span>', '' => ''];
  $dated = isset($o['accepted_at']);
  $keys  = ['accepted_at', 'started_at', 'delivered_at', 'completed_at'];
  $h = '<ol class="o-steps' . ($dated ? ' rt-steps' : '') . '" aria-label="Order progress">';
  foreach (['Accepted', 'Started', 'Delivered', 'Completed'] as $i => $label) {
    $s = $map[$st][$i];
    $d = $dated && $s !== '' && !empty($o[$keys[$i]])
       ? '<small><time datetime="' . e(date('c', strtotime($o[$keys[$i]]))) . '">' . e(date('M j', strtotime($o[$keys[$i]]))) . '</time></small>' : '';
    $h .= '<li' . ($s ? ' class="' . $s . '"' : '') . '>' . e($label) . $note[$s] . $d . '</li>';
  }
  return $h . '</ol>';
}


/* ---------- Saved requests (bookmarks) ---------- */

// Of these post ids, which has this user saved? Returns [post_id => true].
function saved_map($uid, array $postIds){
  global $pdo;
  $ids = array_values(array_unique(array_map('intval', $postIds)));
  if (!$ids) return [];
  $q = $pdo->prepare('SELECT post_id FROM bookmarks WHERE user_id=? AND post_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
  $q->execute(array_merge([(int)$uid], $ids));
  return array_fill_keys(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)), true);
}

// The Save / Saved button. $back must be a forum.php, post.php or saved.php address (bookmark.php checks it).
// The Save button: a bookmark icon. Outline = not saved, filled yellow = saved.
// $withText = true adds the word "Save"/"Saved" (use it on post.php, where there is room).
function save_button($postId, $isSaved, $back, $withText = false){
  $icon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="' . ($isSaved ? 'currentColor' : 'none') . '" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h12v18l-6-4-6 4z"/></svg>';
  $label = $isSaved ? 'Remove from your saved requests' : 'Save this request for later';
  return '<form method="post" action="bookmark.php" class="save-form">'
       . '<input type="hidden" name="csrf" value="' . csrf_token() . '">'
       . '<input type="hidden" name="post_id" value="' . (int)$postId . '">'
       . '<input type="hidden" name="to" value="' . ($isSaved ? '0' : '1') . '">'
       . '<input type="hidden" name="back" value="' . e($back) . '">'
       . '<button class="bm' . ($isSaved ? ' on' : '') . ($withText ? ' txt' : '') . '" aria-pressed="' . ($isSaved ? 'true' : 'false') . '"'
       . ' aria-label="' . e($label) . '" title="' . e($label) . '">' . $icon
       . ($withText ? '<span>' . ($isSaved ? 'Saved' : 'Save') . '</span>' : '') . '</button></form>';
}


/* ---------- Commissioner profile (bio + skills) ---------- */

// Bio and skills for several commissioners in two queries.
// Returns [user_id => ['bio' => string, 'skills' => [subject names], 'skill_ids' => [ints]]].
// Every id asked for is in the result, even if that person has not filled anything in.
function commissioner_profiles(array $userIds){
  global $pdo;
  $ids = array_values(array_unique(array_map('intval', $userIds)));
  if (!$ids) return [];
  $in  = implode(',', array_fill(0, count($ids), '?'));
  $out = array_fill_keys($ids, ['bio' => '', 'skills' => [], 'skill_ids' => []]);
  $q = $pdo->prepare("SELECT user_id, bio FROM commissioner_profiles WHERE user_id IN ($in)");
  $q->execute($ids);
  foreach ($q as $r) $out[(int)$r['user_id']]['bio'] = (string)$r['bio'];
  $q = $pdo->prepare("SELECT cs.user_id, s.id, s.name FROM commissioner_skills cs JOIN subjects s ON s.id=cs.subject_id WHERE cs.user_id IN ($in) ORDER BY s.name");
  $q->execute($ids);
  foreach ($q as $r) { $out[(int)$r['user_id']]['skills'][] = $r['name']; $out[(int)$r['user_id']]['skill_ids'][] = (int)$r['id']; }
  return $out;
}

// Row of small subject chips.
function skill_chips(array $names){
  if (!$names) return '';
  $h = '<ul class="cp-chips" aria-label="Good at">';
  foreach ($names as $n) $h .= '<li>' . e($n) . '</li>';
  return $h . '</ul>';
}

// "About" panel for profile.php. Pass the username from the address (?u=...).
// Shows nothing for non-commissioners. The owner always sees it, with an Edit link.
function commissioner_about_html($username){
  global $pdo, $me;
  $q = $pdo->prepare("SELECT id, role FROM users WHERE username=?");
  $q->execute([(string)$username]);
  $u = $q->fetch();
  if (!$u || $u['role'] !== 'commissioner') return '';
  $cp    = commissioner_profiles([$u['id']])[(int)$u['id']];
  $owner = $u['id'] == $me['id'];
  if ($cp['bio'] === '' && !$cp['skills'] && !$owner) return '';
  $h = '<section class="panel cp-about"><h2>About</h2>';
  if ($cp['bio'] !== '') $h .= '<p class="cp-bio">' . nl2br(e($cp['bio'])) . '</p>';
  elseif ($owner) $h .= '<p class="muted">You have not added a bio yet. Clients are more likely to pick offers from people they know a bit about.</p>';
  if ($cp['skills']) $h .= '<h3 class="cp-sub">Good at</h3>' . skill_chips($cp['skills']);
  if ($owner) $h .= '<p><a class="btn ghost sm" href="' . BASE_URL . 'edit_profile.php">Edit bio &amp; skills</a></p>';
  return $h . '</section>';
}

// Middle truncation: "Very long na pa…me_final.docx". Pair with .mt in type.css.
function mid_trunc($s, $tail = 10){
  $s = (string)$s;
  if (mb_strlen($s) <= $tail + 6) return e($s);
  return '<span class="mt" title="' . e($s) . '"><span class="mt-h">' . e(mb_substr($s, 0, -$tail)) . '</span><span class="mt-t">' . e(mb_substr($s, -$tail)) . '</span></span>';
}