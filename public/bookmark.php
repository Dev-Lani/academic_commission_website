<?php
// Save or un-save a request. The Save button (save_button() in src/helpers.php) posts here.
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();

// Only ever go back to one of our own pages (same idea as vote.php).
$back = $_POST['back'] ?? 'forum.php';
if (!is_string($back) || !preg_match('/^(forum|post|saved)\.php(\?[\w=&%.-]*)?$/', $back)) $back = 'forum.php';

// Saving is a commissioner tool. Anyone else is just sent back.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok() || $me['role'] !== 'commissioner') {
  header('Location: ' . $back); exit;
}

$pid = (int)($_POST['post_id'] ?? 0);
$q = $pdo->prepare('SELECT id FROM posts WHERE id=?');
$q->execute([$pid]);
if (!$q->fetch()) { header('Location: ' . $back); exit; }

if (($_POST['to'] ?? '') === '1') {
  // INSERT IGNORE: saving twice does nothing instead of erroring.
  $pdo->prepare('INSERT IGNORE INTO bookmarks (user_id, post_id) VALUES (?,?)')->execute([$me['id'], $pid]);
} else {
  $pdo->prepare('DELETE FROM bookmarks WHERE user_id=? AND post_id=?')->execute([$me['id'], $pid]);
}
header('Location: ' . $back);
