<?php
// UI effects, included once at the bottom of nav.php:
//  1) live voting: the up/down arrows update without reloading the page
//  2) success animation (animated check) after a transaction
// ---------------------------------------------------------------------
?>
<script>
// Live voting. Progressive: without JavaScript the vote form posts and redirects as before.
(function(){
  document.addEventListener('submit', function(e){
    var f = e.target;
    if (!f.matches || !f.matches('form[action$="vote.php"]')) return;
    var box = f.closest('.votes');
    var up = box && box.querySelector('.vbtn.up'), down = box && box.querySelector('.vbtn.down'), sc = box && box.querySelector('.score');
    if (!up || !down || !sc) return;            // unknown markup: let the normal post happen
    e.preventDefault();
    if (box.dataset.busy || f.querySelector('.vbtn').disabled) return;

    var val = parseInt(f.querySelector('[name=value]').value, 10);
    var oldScore = parseInt(sc.textContent, 10) || 0;
    var cur = up.classList.contains('on') ? 1 : (down.classList.contains('on') ? -1 : 0);
    function show(my, score){
      up.classList.toggle('on', my === 1);
      down.classList.toggle('on', my === -1);
      sc.textContent = score;
    }
    var next = cur === val ? 0 : val;
    show(next, oldScore - cur + next);           // instant, then confirmed by the server below

    box.dataset.busy = '1';
    fetch(f.action, { method: 'POST', body: new FormData(f), credentials: 'same-origin',
                      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } })
      .then(function(r){ return r.ok ? r.json() : Promise.reject(); })
      .then(function(j){ show(j.my, j.score); })
      .catch(function(){ show(cur, oldScore); }) // failed: put it back
      .then(function(){ delete box.dataset.busy; });
  });
})();
</script>
<?php
// Success animation. An action sets $_SESSION['fx'] = 'approved' | 'agreed' | 'accepted' | 'delivered' | 'rated' and
// redirects; the next page load shows it once and clears the flag. Needs $pdo and $me from the page.
$fxKind = $_SESSION['fx'] ?? null;
unset($_SESSION['fx']);

$fxKinds = [
  // kind       => [style, title, text]
  'approved'  => ['card',  'Payment released',   'The work was approved and the payment is on its way to the commissioner.'],
  'agreed'    => ['toast', 'Order started',      'The payment is now held safely in escrow.'],
  'accepted'  => ['toast', 'Offer accepted',     'Waiting for the commissioner to agree.'],
  'delivered' => ['toast', 'Delivered',          'The client has been notified.'],
  'rated'     => ['toast', 'Thanks for rating',  'Your rating has been saved.'],
];
if (!$fxKind || !isset($fxKinds[$fxKind])) return;
[$fxStyle, $fxTitle, $fxText] = $fxKinds[$fxKind];

// The card also shows the order amount (only if this user is part of the order).
$fxAmount = null;
if ($fxStyle === 'card' && isset($pdo, $me)) {
  $fq = $pdo->prepare('SELECT price FROM orders WHERE id=? AND (client_id=? OR commissioner_id=?)');
  $fq->execute([(int)($_GET['id'] ?? 0), $me['id'], $me['id']]);
  $fxAmount = $fq->fetchColumn();
}

$fxSvg = function ($stroke, $w, $t) {
  return '<svg width="72" height="72" viewBox="0 0 72 72" fill="none" aria-hidden="true">'
       . '<circle class="fx-ring" cx="36" cy="36" r="28" stroke="' . $stroke . '" stroke-width="' . $w . '" stroke-linecap="round" transform="rotate(-90 36 36)"/>'
       . '<path class="fx-tick" d="M24 37l9 9 16-19" stroke="' . $stroke . '" stroke-width="' . $t . '" stroke-linecap="round" stroke-linejoin="round"/></svg>';
};
$fxDots = '';
$fxCols = ['#FFE066', '#3B4BDB', '#1F8A5B', '#FFE066', '#3B4BDB'];
for ($i = 0; $i < 10; $i++) {
  $a = $i / 10 * M_PI * 2; $r = 62 + ($i % 2) * 14;
  $fxDots .= '<span class="fx-dot" style="background:' . $fxCols[$i % 5] . ';--dx:' . round(cos($a) * $r) . 'px;--dy:' . round(sin($a) * $r) . 'px"></span>';
}
?>
<style>
@keyframes fx-card{0%{opacity:0;transform:translateY(14px) scale(.96)}100%{opacity:1;transform:none}}
@keyframes fx-pop{0%{transform:scale(.4);opacity:0}60%{transform:scale(1.12);opacity:1}100%{transform:scale(1);opacity:1}}
@keyframes fx-ring{to{stroke-dashoffset:0}}
@keyframes fx-tick{to{stroke-dashoffset:0}}
@keyframes fx-pulse{0%{transform:scale(.8);opacity:.55}100%{transform:scale(1.9);opacity:0}}
@keyframes fx-burst{0%{transform:translate(0,0) scale(.3);opacity:1}100%{transform:translate(var(--dx),var(--dy)) scale(1);opacity:0}}
@keyframes fx-rise{0%{opacity:0;transform:translateY(8px)}100%{opacity:1;transform:none}}
@keyframes fx-out{to{opacity:0}}
.fx{position:fixed;inset:0;z-index:90;display:grid;place-items:center;pointer-events:none}
.fx.is-out{animation:fx-out .25s forwards}
.fx-scrim{position:absolute;inset:0;background:rgba(27,35,64,.45);pointer-events:auto}
.fx-card{position:relative;width:min(22.5rem,calc(100vw - 2rem));box-sizing:border-box;background:var(--surface,#fff);border:1px solid var(--line,#D9DDEA);border-radius:22px;padding:2.2rem 2rem 1.6rem;text-align:center;box-shadow:0 18px 44px rgba(27,35,64,.2);pointer-events:auto;animation:fx-card .45s cubic-bezier(.2,.8,.2,1) both}
.fx-badge{position:relative;width:120px;height:120px;margin:0 auto 1rem;display:grid;place-items:center}
.fx-disc{position:relative;width:92px;height:92px;border-radius:50%;background:var(--ok-bg,#E3F4EB);display:grid;place-items:center;animation:fx-pop .5s cubic-bezier(.2,.9,.3,1.2) .05s both}
.fx-pulse{position:absolute;inset:14px;border-radius:50%;background:var(--ok,#1F8A5B);animation:fx-pulse .9s ease-out .55s both}
.fx-dot{position:absolute;left:50%;top:50%;width:8px;height:8px;margin:-4px 0 0 -4px;border-radius:50%;animation:fx-burst .8s ease-out .6s both}
.fx-ring{stroke-dasharray:176;stroke-dashoffset:176;animation:fx-ring .55s ease-out .15s forwards}
.fx-tick{stroke-dasharray:50;stroke-dashoffset:50;animation:fx-tick .35s ease-out .6s forwards}
.fx-card h2{margin:0;font-size:1.6rem}
.fx-card p{margin:.5rem 0 0;color:var(--muted,#5E6785)}
.fx-amt{margin-top:1.1rem;padding:.8rem 1rem;border-radius:12px;background:var(--paper,#F4F6FB);display:flex;justify-content:space-between;align-items:center;text-align:left}
.fx-amt small{display:block;color:var(--muted,#5E6785)}
.fx-amt b{font:700 1.4rem var(--font-d,inherit)}
.fx-card .btn{margin-top:1.1rem;width:100%}
.fx-t1{animation:fx-rise .4s ease-out .75s both}.fx-t2{animation:fx-rise .4s ease-out .9s both}.fx-t3{animation:fx-rise .4s ease-out 1.05s both}
/* toast */
.fx.toast{inset:auto;top:72px;left:50%;transform:translateX(-50%);display:block}
.fx-toast{display:flex;align-items:center;gap:.75rem;padding:.7rem 1rem .7rem .8rem;border-radius:12px;background:var(--ink,#1B2340);color:#fff;box-shadow:0 12px 32px rgba(27,35,64,.3);pointer-events:auto;animation:fx-card .4s cubic-bezier(.2,.8,.2,1) both;max-width:calc(100vw - 2rem)}
.fx-toast svg{width:30px;height:30px;flex:none}
.fx-toast b{display:block}
.fx-toast small{color:#C9CEE8}
@media (prefers-reduced-motion:reduce){
  .fx-card,.fx-toast,.fx-disc,.fx-t1,.fx-t2,.fx-t3{animation:none}
  .fx-ring,.fx-tick{animation:none;stroke-dashoffset:0}
  .fx-pulse,.fx-dot{display:none}
}
</style>
<?php if ($fxStyle === 'card'): ?>
<div class="fx" id="fx" role="dialog" aria-modal="true" aria-labelledby="fx-h" data-ms="5000">
  <div class="fx-scrim" data-close></div>
  <div class="fx-card">
    <div class="fx-badge" aria-hidden="true"><span class="fx-pulse"></span><?= $fxDots ?><span class="fx-disc"><?= $fxSvg('#1F8A5B', 4, 5) ?></span></div>
    <h2 id="fx-h" class="fx-t1"><?= e($fxTitle) ?></h2>
    <p class="fx-t2"><?= e($fxText) ?></p>
    <?php if ($fxAmount !== null && $fxAmount !== false): ?>
      <div class="fx-amt fx-t3"><div><small>Amount</small><b><?= money($fxAmount) ?></b></div><span class="tag ok">Completed</span></div>
    <?php endif; ?>
    <button type="button" class="btn fx-t3" data-close>Continue</button>
  </div>
</div>
<?php else: ?>
<div class="fx toast" id="fx" role="status" aria-live="polite" data-ms="3600">
  <div class="fx-toast"><?= $fxSvg('#6EE0A8', 6, 7) ?><div><b><?= e($fxTitle) ?></b><small><?= e($fxText) ?></small></div></div>
</div>
<?php endif; ?>
<script>
// Close on click / Esc, or by itself after a few seconds. Then the page behaves as usual.
(function(){
  var el = document.getElementById('fx'); if (!el) return;
  var done = false;
  function close(){
    if (done) return; done = true;
    document.removeEventListener('keydown', key);
    el.classList.add('is-out');
    setTimeout(function(){ el.remove(); }, 260);
  }
  function key(e){ if (e.key === 'Escape') close(); }
  el.querySelectorAll('[data-close]').forEach(function(b){ b.addEventListener('click', close); });
  document.addEventListener('keydown', key);
  setTimeout(close, parseInt(el.dataset.ms, 10) || 4000);
})();
</script>