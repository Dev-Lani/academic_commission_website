<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();
if ($me['role'] !== 'client') { header('Location: forum.php'); exit; }

$subjects = $pdo->query('SELECT id, name, slug FROM subjects ORDER BY name')->fetchAll();
$types = post_types();
$err = '';
$old = ['subject_id'=>'','title'=>'','type'=>'','pages'=>'','deadline'=>'','description'=>''];

if (isset($_GET['s'])) {
  foreach ($subjects as $s) if ($s['slug'] === $_GET['s']) $old['subject_id'] = $s['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  foreach ($old as $k => $_) $old[$k] = trim($_POST[$k] ?? '');
  $dl  = DateTime::createFromFormat('Y-m-d\TH:i', $old['deadline']);
  $sid = (int)$old['subject_id'];

  if (!csrf_ok())                                         $err = 'Your session expired. Reload the page and try again.';
  elseif (unrated_order($me['id']))                       $err = 'Rate your completed order first (My orders), then post a new request.';
  elseif (!in_array($sid, array_column($subjects, 'id'))) $err = 'Choose a subject.';
  elseif (mb_strlen($old['title']) < 5 || mb_strlen($old['title']) > 120) $err = 'Title must be 5 to 120 characters.';
  elseif (!in_array($old['type'], $types, true))          $err = 'Choose the type of work.';
  elseif ((int)$old['pages'] < 1 || (int)$old['pages'] > 500) $err = 'Pages must be between 1 and 500.';
  elseif (!$dl || $dl->getTimestamp() <= time())          $err = 'Pick a deadline in the future.';
  elseif (mb_strlen($old['description']) < 20)            $err = 'Describe the work in at least 20 characters so commissioners know what to offer.';
  else {
    $pdo->prepare('INSERT INTO posts (user_id, subject_id, title, type, pages, deadline, description)
                   VALUES (?,?,?,?,?,?,?)')
        ->execute([$me['id'], $sid, $old['title'], $old['type'], (int)$old['pages'],
                   $dl->format('Y-m-d H:i:s'), $old['description']]);
    header('Location: post.php?id=' . $pdo->lastInsertId());
    exit;
  }
}
if ($old['pages'] === '') $old['pages'] = '5';
?>
<!doctype html>
<html lang="en"><head><title>New request · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap">
  <div class="page-head">
    <p><a href="forum.php">Forum</a> / New request</p>
    <h1>Create a request</h1>
    <p>Commissioners will send you private offers with their own price. The clearer the request, the better the offers.</p>
  </div>
  <?php if ($err): ?><div class="alert err" role="alert"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="cf" id="cf" novalidate>
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div class="cf-main">
      <section class="panel cf-sec">
        <div class="cf-sh"><span class="n">1</span><h3>The work</h3></div>
        <div class="field" style="margin:0"><label class="lbl" for="title">Title</label>
          <input type="text" id="title" name="title" maxlength="120" value="<?= e($old['title']) ?>" required>
          <p class="hint cf-line"><span>Example: 5-page reflection paper on Rizal's novels</span><span id="title-count">0 / 120</span></p></div>

        <div><span class="lbl">Subject</span>
          <div class="cf-chips fold" id="subj-chips">
            <?php foreach ($subjects as $i => $s): ?>
              <label class="fc<?= $i >= 8 ? ' x' : '' ?>"><input type="radio" name="subject_id" value="<?= (int)$s['id'] ?>"<?= $old['subject_id'] == $s['id'] ? ' checked' : '' ?>><span><?= e($s['name']) ?></span></label>
            <?php endforeach; ?>
            <button type="button" class="qk" id="subj-more">Show all <?= count($subjects) ?> subjects ▾</button>
          </div></div>

        <div><span class="lbl">Type of work</span>
          <?php foreach (post_type_groups() as $kindKey => $grp): ?>
            <p class="cf-group"><?= e($grp['label']) ?></p>
            <div class="cf-chips">
              <?php foreach ($grp['types'] as $t): ?>
                <label class="fc"><input type="radio" name="type" value="<?= e($t) ?>" data-kind="<?= e($kindKey) ?>"<?= $old['type'] === $t ? ' checked' : '' ?>><span><?= e($t) ?></span></label>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?></div>
      </section>

      <section class="panel cf-sec">
        <div class="cf-sh"><span class="n">2</span><h3>Deadline and size</h3></div>
        <div class="cf-row">
          <div><label class="lbl" for="pages">Pages / items</label>
            <div class="cf-step">
              <button type="button" class="btn ghost" data-step="-1" aria-label="Fewer pages">−</button>
              <input type="number" id="pages" name="pages" min="1" max="500" value="<?= e($old['pages']) ?>" required>
              <button type="button" class="btn ghost" data-step="1" aria-label="More pages">+</button>
            </div></div>
          <div><label class="lbl" for="deadline">Deadline</label>
            <input type="datetime-local" id="deadline" name="deadline" min="<?= date('Y-m-d\TH:i') ?>" value="<?= e($old['deadline']) ?>" required>
            <div class="cf-presets">
              <button type="button" class="qk" data-hours="24">24 hours</button><button type="button" class="qk" data-hours="72">3 days</button>
              <button type="button" class="qk" data-hours="168">1 week</button><button type="button" class="qk" data-hours="336">2 weeks</button>
            </div></div>
        </div>
      </section>

      <section class="panel cf-sec">
        <div class="cf-sh"><span class="n">3</span><h3>Description</h3></div>
        <div class="field" style="margin:0"><label class="lbl" for="description">What do you need?</label>
          <textarea id="description" name="description" maxlength="3000" required><?= e($old['description']) ?></textarea>
          <p class="hint cf-line"><span>At least 20 characters. Include requirements, format, and sources.</span><span id="desc-count">0 / 3000</span></p>
          <div class="cf-presets"><span class="muted" style="font-size:.85rem;align-self:center">Add:</span>
            <button type="button" class="qk" data-add="Format: APA 7th edition.">+ Format (APA, MLA)</button>
            <button type="button" class="qk" data-add="Required sources: at least 5 references from the last 5 years.">+ Required sources</button>
            <button type="button" class="qk" data-add="Rubric or grading criteria: ">+ Rubric or grading</button>
            <button type="button" class="qk" data-add="Tone and language: ">+ Tone / language</button></div></div>
      </section>
    </div>

    <aside class="cf-side">
      <section class="panel cf-card"><h3>Preview in the forum</h3>
        <div class="cf-prev"><div class="k" id="pv-kick">Subject · Type</div><div class="t" id="pv-title">Your title appears here</div><div class="muted" id="pv-meta" style="font-size:.85rem"></div></div></section>
      <section class="panel cf-card"><h3>Checklist <span class="muted" style="font-weight:400;font-size:.85rem" id="ck-n"></span></h3>
        <div class="cf-prog"><i id="ck-bar"></i></div>
        <div class="cf-ck" data-ck="title"><s>✓</s>Title</div>
        <div class="cf-ck" data-ck="subject"><s>✓</s>Subject</div>
        <div class="cf-ck" data-ck="type"><s>✓</s>Type of work</div>
        <div class="cf-ck" data-ck="pages"><s>✓</s>Pages</div>
        <div class="cf-ck" data-ck="deadline"><s>✓</s>Deadline in the future</div>
        <div class="cf-ck" data-ck="desc"><s>✓</s>Description</div></section>
      <button class="btn" id="post-btn">Post request</button>
      <a class="btn ghost" href="forum.php" style="text-align:center">Cancel</a>
    </aside>
  </form>
</main>
<script src="<?= BASE_URL ?>assets/js/create_post.js" defer></script>
</body></html>
