<?php
// Commissioners edit their bio and the subjects they are good at.
// Shown on their profile page and next to their offers (post.php).
require dirname(__DIR__) . '/src/bootstrap.php';
$me = require_login();
if ($me['role'] !== 'commissioner') { header('Location: profile.php?u=' . urlencode($me['username'])); exit; }

const BIO_MAX = 500;
const SKILLS_MAX = 5;

$subjects = $pdo->query('SELECT id, name FROM subjects ORDER BY name')->fetchAll();
$validIds = array_map('intval', array_column($subjects, 'id'));

$err = '';
$bio = '';
$picked = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $bio    = trim((string)($_POST['bio'] ?? ''));
  $picked = array_values(array_unique(array_map('intval', (array)($_POST['skills'] ?? []))));
  $picked = array_values(array_intersect($picked, $validIds)); // ignore anything that is not a real subject

  if (!csrf_ok()) $err = 'Your session expired. Please try again.';
  elseif (mb_strlen($bio) > BIO_MAX) $err = 'Your bio is too long. Keep it to ' . BIO_MAX . ' characters or fewer.';
  elseif (count($picked) > SKILLS_MAX) $err = 'Pick up to ' . SKILLS_MAX . ' subjects.';
  elseif (preg_match('~https?://|www\.|[\w.+-]+@[\w-]+\.[\w.]+|\d[\d\s().-]{8,}\d~i', $bio)) $err = 'No links, emails or phone numbers in your bio, please.';

  if ($err === '') {
    $pdo->beginTransaction();
    try {
      $pdo->prepare('INSERT INTO commissioner_profiles (user_id, bio) VALUES (?,?) ON DUPLICATE KEY UPDATE bio=VALUES(bio)')
          ->execute([$me['id'], $bio]);
      $pdo->prepare('DELETE FROM commissioner_skills WHERE user_id=?')->execute([$me['id']]);
      $ins = $pdo->prepare('INSERT INTO commissioner_skills (user_id, subject_id) VALUES (?,?)');
      foreach ($picked as $sid) $ins->execute([$me['id'], $sid]);
      $pdo->commit();
    } catch (Throwable $ex) {
      $pdo->rollBack();
      $err = 'Could not save. Please try again.';
    }
    if ($err === '') { header('Location: edit_profile.php?msg=saved'); exit; }
  }
} else {
  $cp     = commissioner_profiles([$me['id']])[(int)$me['id']];
  $bio    = $cp['bio'];
  $picked = $cp['skill_ids'];
}
$msg = $_GET['msg'] ?? '';
?>
<!doctype html>
<html lang="en"><head><title>Edit your profile · Academic Commission</title><?php include TEMPLATE_PATH . 'head.php'; ?></head>
<body>
<?php include TEMPLATE_PATH . 'nav.php'; ?>
<main class="wrap ep">
  <div class="page-head">
    <h1>Your bio &amp; skills</h1>
    <p>Clients see this on your profile and next to your offers, so they have more than a message and a rating to go on.</p>
  </div>

  <section class="panel ep-card">
    <?php if ($msg === 'saved'): ?><div class="alert ok">Saved. <a href="profile.php?u=<?= urlencode($me['username']) ?>">View your profile</a></div><?php endif; ?>
    <?php if ($err): ?><div class="alert err" role="alert"><?= e($err) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

      <div class="ep-sec">
        <label class="lbl" for="bio">Short bio</label>
        <textarea id="bio" name="bio" maxlength="<?= BIO_MAX ?>" placeholder="e.g. 3rd-year BS Psychology student. I write clear, well-cited essays and I always deliver on time."><?= e($bio) ?></textarea>
        <p class="hint ep-line"><span>No links, emails or phone numbers.</span><span id="bio-count">0 / <?= BIO_MAX ?></span></p>
      </div>

      <div class="ep-sec ep-div">
        <div class="ep-sk">
          <span class="lbl" id="skills-lbl">Subjects you are good at (pick up to <?= SKILLS_MAX ?>)</span>
          <span class="ep-pill" id="cp-count" aria-live="polite"></span>
        </div>
        <div class="cp-pick" role="group" aria-labelledby="skills-lbl">
          <?php foreach ($subjects as $s): $sid = (int)$s['id']; ?>
            <label class="cp-opt"><input type="checkbox" name="skills[]" value="<?= $sid ?>"<?= in_array($sid, $picked, true) ? ' checked' : '' ?>><span><?= e($s['name']) ?></span></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="ep-foot">
        <a class="btn ghost" href="profile.php?u=<?= urlencode($me['username']) ?>">Cancel</a>
        <button class="btn">Save changes</button>
      </div>
    </form>
  </section>
</main>
<script>
// Stop at 5 ticks so people see the limit before they submit (the server checks it too).
(function(){
  var boxes = document.querySelectorAll('.cp-pick input'), note = document.getElementById('cp-count'), max = <?= SKILLS_MAX ?>;
  function sync(){
    var n = document.querySelectorAll('.cp-pick input:checked').length;
    boxes.forEach(function(b){ b.disabled = !b.checked && n >= max; });
    note.textContent = n + ' of ' + max + ' selected';
  }
  boxes.forEach(function(b){ b.addEventListener('change', sync); });
  sync();

  // Live character count for the bio
  var bio = document.getElementById('bio'), cnt = document.getElementById('bio-count');
  function count(){ cnt.textContent = bio.value.length + ' / <?= BIO_MAX ?>'; }
  bio.addEventListener('input', count);
  count();
})();
</script>
</body></html>
