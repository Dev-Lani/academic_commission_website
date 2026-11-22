// Register: password field. Rewards early (outline draws while typing), punishes late (errors after 8+ chars or on blur).
(function () {
  var inp = document.getElementById('password'), box = document.getElementById('pw'), hint = document.getElementById('pw-hint');
  if (!inp || !box || !hint) return;
  var base = hint.textContent,
      COMMON = /^(password|passw0rd|qwerty|iloveyou|admin|welcome|letmein|12345|abc123|dragon|monkey)/;

  function weak(v) { return v.length < 16 && COMMON.test(v.toLowerCase().replace(/[^a-z0-9]/g, '')); }
  function score(v) {
    if (weak(v)) return Math.min(v.length / 12, .6);
    var kinds = /[a-z]/.test(v) + /[A-Z]/.test(v) + /\d/.test(v) + /[^A-Za-z0-9]/.test(v);
    return Math.min(1, v.length / 12) * (kinds >= 2 || v.length >= 14 ? 1 : .85);
  }
  function paint(final) {
    var v = inp.value, s = score(v), msg = base, bad = false;
    box.style.setProperty('--pw', s);
    box.classList.toggle('done', s >= 1);
    if (v.length >= 8 && weak(v)) { msg = 'That password is easy to guess. Add more words or characters.'; bad = true; }
    else if (final && v && v.length < 8) { msg = 'Use at least 8 characters.'; bad = true; }
    box.classList.toggle('bad', bad);
    hint.classList.toggle('bad', bad);
    inp.setAttribute('aria-invalid', String(bad));
    if (hint.textContent !== msg) hint.textContent = msg;
  }
  inp.addEventListener('input', function () { paint(false); });
  inp.addEventListener('blur', function () { paint(true); });
  paint(false);
})();