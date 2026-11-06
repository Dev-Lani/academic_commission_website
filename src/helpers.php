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

function post_types(){
  return ['Essay','Research paper','Thesis chapter','Editing / proofreading','Reflection paper',
          'Case study','Report','Presentation (PPT)','Programming project','Problem set','Other'];
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