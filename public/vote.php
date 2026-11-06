<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();

$back = $_POST['back'] ?? 'forum.php';
if (!preg_match('/^(forum|post)\.php(\?[\w=&%.-]*)?$/', $back)) $back = 'forum.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) { header('Location: ' . $back); exit; }

$pid = (int)($_POST['post_id'] ?? 0);
$val = (int)($_POST['value'] ?? 0);
if (!in_array($val, [1, -1], true)) { header('Location: ' . $back); exit; }

$q = $pdo->prepare('SELECT user_id FROM posts WHERE id=?');
$q->execute([$pid]);
$post = $q->fetch();

// walang vote sa sariling post o sa post na hindi existing
if (!$post || $post['user_id'] == $me['id']) { header('Location: ' . $back); exit; }

$pdo->beginTransaction();
$q = $pdo->prepare('SELECT value FROM votes WHERE user_id=? AND post_id=?');
$q->execute([$me['id'], $pid]);
$cur = $q->fetch();

if ($cur && (int)$cur['value'] === $val) {
  // pinindot ulit ang parehong arrow = tanggalin ang vote
  $pdo->prepare('DELETE FROM votes WHERE user_id=? AND post_id=?')->execute([$me['id'], $pid]);
} else {
  $pdo->prepare('INSERT INTO votes (user_id, post_id, value) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([$me['id'], $pid, $val]);
}
$pdo->prepare('UPDATE posts SET score=(SELECT COALESCE(SUM(value),0) FROM votes WHERE post_id=?) WHERE id=?')
    ->execute([$pid, $pid]);
$pdo->commit();

header('Location: ' . $back);