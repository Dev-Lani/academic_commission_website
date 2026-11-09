// Rate form (completed order): live preview, quick tags, inline validation.
// The server stays the source of truth; this only enhances.
(function () {
  var f = document.getElementById('rate-form');
  if (!f) return;
  var ta = f.elements.comment, err = document.getElementById('rt-err'),
      hint = document.getElementById('rt-hint'), rule = document.getElementById('rt-rule'), count = document.getElementById('rt-count'),
      pvFace = document.getElementById('rt-pv-face'), pvLabel = document.getElementById('rt-pv-label'), pvText = document.getElementById('rt-pv-text'),
      tags = f.querySelectorAll('[data-add]');
  f.noValidate = true; // inline messages replace the browser bubbles
  f.classList.add('rt-js');

  function pick() { return f.querySelector('input[name="stars"]:checked'); }
  function stars() { var c = pick(); return c ? parseInt(c.value, 10) : 0; }
  function need(s) { return s && s < 3 ? 30 : 10; }
  function len() { return Array.from(ta.value.trim()).length; }
  function ruleText(s) { return s && s < 3 ? 'Ratings below 3 stars need a reason (30+ characters).' : 'At least 10 characters.'; }

  function update() {
    var s = stars(), n = len(), c = pick(), tile = c ? c.nextElementSibling : null, t = ta.value.trim();
    rule.textContent = ruleText(s);
    count.textContent = ta.value.length + ' / 1000';
    hint.classList.toggle('warn', n > 0 && n < need(s));
    Array.prototype.forEach.call(tags, function (b) {
      var on = ta.value.indexOf(b.dataset.add) > -1;
      b.setAttribute('aria-pressed', String(on));
    });
    pvFace.textContent = '';
    if (tile) pvFace.appendChild(tile.querySelector('svg').cloneNode(true));
    pvLabel.textContent = tile ? tile.querySelector('b').textContent : 'No rating yet';
    pvText.textContent = t || 'Your comment appears here.';
    pvText.classList.toggle('rt-empty', !t);
  }

  function show(msg) { err.textContent = msg; err.hidden = false; }
  function clear() { if (!err.hidden) { err.hidden = true; err.textContent = ''; } }

  Array.prototype.forEach.call(tags, function (b) {
    b.addEventListener('click', function () {
      var t = b.dataset.add, v = ta.value;
      ta.value = (v.indexOf(t) > -1
        ? v.replace(t, '').replace(/ {2,}/g, ' ').trim()
        : (v.trim() ? v.replace(/\s+$/, '') + ' ' : '') + t).slice(0, 1000);
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });

  f.addEventListener('input', function () { clear(); update(); });
  f.addEventListener('change', function () { clear(); update(); });
  f.addEventListener('submit', function (e) {
    var s = stars(), msg = '', at = null;
    if (!s) { msg = 'Pick a rating first.'; at = f.querySelector('input[name="stars"]'); }
    else if (len() < need(s)) { msg = ruleText(s); at = ta; }
    if (!msg) return;
    e.preventDefault();
    show(msg);
    at.focus();
  });

  update();
})();