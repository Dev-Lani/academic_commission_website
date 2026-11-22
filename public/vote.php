<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();

// The page sends the vote with fetch() so it does not reload. Without JavaScript the old
// form post still works: it redirects back, exactly as before.
$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

$back = $_POST['back'] ?? 'forum.php';
if (!preg_match('/^(forum|post)\.php(\?[\w=&%.-]*)?$/', $back)) $back = 'forum.php';

function vote_done($ajax, $back, $code = 200, array $data = []){
  if ($ajax) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data + ['ok' => $code === 200]);
    exit;
  }
  header('Location: ' . $back); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) vote_done($ajax, $back, 403);

$pid = (int)($_POST['post_id'] ?? 0);
$val = (int)($_POST['value'] ?? 0);
if (!in_array($val, [1, -1], true)) vote_done($ajax, $back, 400);

$q = $pdo->prepare('SELECT user_id FROM posts WHERE id=?');
$q->execute([$pid]);
$post = $q->fetch();

// walang vote sa sariling post o sa post na hindi existing
if (!$post || $post['user_id'] == $me['id']) vote_done($ajax, $back, 403);

$pdo->beginTransaction();
$q = $pdo->prepare('SELECT value FROM votes WHERE user_id=? AND post_id=?');
$q->execute([$me['id'], $pid]);
$cur = $q->fetch();

if ($cur && (int)$cur['value'] === $val) {
  // pinindot ulit ang parehong arrow = tanggalin ang vote
  $pdo->prepare('DELETE FROM votes WHERE user_id=? AND post_id=?')->execute([$me['id'], $pid]);
  $my = 0;
} else {
  $pdo->prepare('INSERT INTO votes (user_id, post_id, value) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([$me['id'], $pid, $val]);
  $my = $val;
}
$pdo->prepare('UPDATE posts SET score=(SELECT COALESCE(SUM(value),0) FROM votes WHERE post_id=?) WHERE id=?')
    ->execute([$pid, $pid]);
$s = $pdo->prepare('SELECT score FROM posts WHERE id=?');
$s->execute([$pid]);
$score = (int)$s->fetchColumn();
$pdo->commit();

vote_done($ajax, $back, 200, ['score' => $score, 'my' => $my]);