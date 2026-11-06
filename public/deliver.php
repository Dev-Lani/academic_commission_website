<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me   = require_login();
$id   = (int)($_POST['order_id'] ?? 0);
$back = 'order.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok() || $me['role'] !== 'commissioner') {
  header('Location: ' . $back); exit;
}

$q = $pdo->prepare('SELECT * FROM orders WHERE id=?');
$q->execute([$id]);
$o = $q->fetch();
if (!$o || $o['commissioner_id'] != $me['id'] || $o['status'] !== 'active') { header('Location: orders.php'); exit; }

const MAX_UPLOAD = 10 * 1024 * 1024; // 10 MB each
$final = $_FILES['final'] ?? null;
$prev  = $_FILES['preview'] ?? null;
$finalExt = ['pdf','doc','docx','ppt','pptx','xls','xlsx','zip','txt','py','java','js','c','cpp','png','jpg','jpeg'];
$prevMime = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg'];

function file_problem($f, $label){
  if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) return "Choose the $label file.";
  if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > MAX_UPLOAD)
    return "The $label file is too large (max 10 MB).";
  if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return "The $label file did not upload. Try again.";
  return '';
}

$err = file_problem($final, 'final') ?: file_problem($prev, 'preview');
if (!$err) {
  $ext = strtolower(pathinfo($final['name'], PATHINFO_EXTENSION));
  $mime = (new finfo(FILEINFO_MIME_TYPE))->file($prev['tmp_name']);
  if (!in_array($ext, $finalExt, true))   $err = 'That final file type is not allowed. Use PDF, Word, PowerPoint, Excel, ZIP, text, code, or an image.';
  elseif (!isset($prevMime[$mime]))       $err = 'The preview must be a PDF, PNG, or JPG so the client can view it in the browser.';
}
if ($err) { $_SESSION['flash_err'] = $err; header('Location: ' . $back); exit; }

if (!is_dir(PRIVATE_DIR)) mkdir(PRIVATE_DIR, 0775, true);
$fs = bin2hex(random_bytes(16));
$ps = bin2hex(random_bytes(16));
$name = mb_substr(preg_replace('/[^\w. -]/u', '_', basename($final['name'])), 0, 150);

if (!move_uploaded_file($final['tmp_name'], PRIVATE_DIR . $fs) || !move_uploaded_file($prev['tmp_name'], PRIVATE_DIR . $ps)) {
  @unlink(PRIVATE_DIR . $fs); @unlink(PRIVATE_DIR . $ps);
  $_SESSION['flash_err'] = 'Could not save the files on the server.';
  header('Location: ' . $back); exit;
}

$now = time();
$pdo->beginTransaction();
$u = $pdo->prepare("UPDATE orders SET status='delivered', delivered_at=?, review_by=? WHERE id=? AND status='active'");
$u->execute([date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + REVIEW_HOURS * 3600), $id]);
if (!$u->rowCount()) {
  $pdo->rollBack();
  @unlink(PRIVATE_DIR . $fs); @unlink(PRIVATE_DIR . $ps);
  header('Location: ' . $back); exit;
}
$pdo->prepare('INSERT INTO order_outputs (order_id, final_name, final_stored, preview_stored, preview_mime, created_at) VALUES (?,?,?,?,?,?)')
    ->execute([$id, $name, $fs, $ps, $mime, date('Y-m-d H:i:s', $now)]);
notify($o['client_id'], $o['post_id'], 'delivered', $id);
$pdo->commit();

header('Location: ' . $back);