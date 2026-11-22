// Toasts, confirm dialog, and automatic conversion of .alert boxes into toasts.
//   toast('Saved', 'ok' | 'err' | 'info')
//   confirmDialog('Are you sure?').then(function (yes) { ... })
//   <form data-confirm="Message"> or <button data-confirm="Message"> asks first, then submits.
(function () {
  var host = null, MAX = 4;

  function getHost() {
    if (!host) {
      host = document.createElement('div');
      host.className = 'tt-host';
      document.body.appendChild(host);
    }
    return host;
  }

  function toast(msg, type, ms) {
    type = type === 'ok' || type === 'err' ? type : 'info';
    var el = document.createElement('div');
    el.className = 'tt tt-' + type;
    el.setAttribute('role', type === 'err' ? 'alert' : 'status');

    var NS = 'http://www.w3.org/2000/svg';
    function s(tag, attrs) {
      var n = document.createElementNS(NS, tag);
      for (var k in attrs) n.setAttribute(k, attrs[k]);
      return n;
    }
    var ic = document.createElement('span');
    ic.className = 'tt-ic'; ic.setAttribute('aria-hidden', 'true');
    var svg = s('svg', { width: 34, height: 34, viewBox: '0 0 34 34', fill: 'none' });
    svg.appendChild(s('circle', { 'class': 'trk', cx: 17, cy: 17, r: 10, 'stroke-width': 3 }));
    var ring = s('circle', { 'class': 'ring', cx: 17, cy: 17, r: 10, 'stroke-width': 3, 'stroke-linecap': 'round', transform: 'rotate(-90 17 17)' });
    svg.appendChild(ring);
    svg.appendChild(s('path', {
      d: type === 'ok' ? 'M12 17.3l3.6 3.6L22 13.6' : type === 'err' ? 'M17 11.5v6M17 22h.01' : 'M17 16v6M17 12h.01',
      stroke: 'currentColor', 'stroke-width': 2.4, 'stroke-linecap': 'round', 'stroke-linejoin': 'round'
    }));
    ic.appendChild(svg);

    var body = document.createElement('div');
    body.className = 'tt-b';
    if (typeof msg === 'string') body.textContent = msg; else body.appendChild(msg);

    var x = document.createElement('button');
    x.type = 'button'; x.className = 'tt-x'; x.setAttribute('aria-label', 'Dismiss'); x.textContent = '×';

    el.appendChild(ic); el.appendChild(body); el.appendChild(x);
    var h = getHost();
    while (h.children.length >= MAX) h.firstChild.remove();
    h.appendChild(el);

    var left = ms || (body.querySelector('a') ? 7000 : 4000), started = 0, timer = 0;
    ring.style.animationDuration = left + 'ms';
    function run() { started = Date.now(); el.classList.remove('paused'); timer = setTimeout(close, left); }
    function pause() { clearTimeout(timer); left -= Date.now() - started; el.classList.add('paused'); } 
    function close() {
      clearTimeout(timer);
      el.classList.add('out');
      setTimeout(function () { el.remove(); }, 300);
    }
    document.documentElement.classList.add('tt-on');
    el.addEventListener('mouseenter', pause);
    el.addEventListener('mouseleave', run);
    x.addEventListener('click', close);
    run();
  }

  /* ---------- confirm dialog (replaces window.confirm) ---------- */
  function confirmDialog(message, okText) {
    return new Promise(function (resolve) {
      var prev = document.activeElement;
      var wrap = document.createElement('div');
      wrap.className = 'cd';
      var box = document.createElement('div');
      box.className = 'cd-box';
      box.setAttribute('role', 'alertdialog'); box.setAttribute('aria-modal', 'true');
      var p = document.createElement('p'); p.textContent = message;
      var act = document.createElement('div'); act.className = 'cd-act';
      var no = document.createElement('button'); no.type = 'button'; no.className = 'btn ghost'; no.textContent = 'Cancel';
      var yes = document.createElement('button'); yes.type = 'button'; yes.className = 'btn'; yes.textContent = okText || 'Continue';
      act.appendChild(no); act.appendChild(yes);
      box.appendChild(p); box.appendChild(act); wrap.appendChild(box);
      document.body.appendChild(wrap);
      yes.focus();

      function done(v) {
        document.removeEventListener('keydown', key);
        wrap.remove();
        if (prev && prev.focus) prev.focus();
        resolve(v);
      }
      function key(e) { if (e.key === 'Escape') done(false); }
      document.addEventListener('keydown', key);
      no.addEventListener('click', function () { done(false); });
      yes.addEventListener('click', function () { done(true); });
      wrap.addEventListener('mousedown', function (e) { if (e.target === wrap) done(false); });
    });
  }

  window.toast = toast;
  window.confirmDialog = confirmDialog;

  /* ---------- data-confirm on forms and submit buttons ---------- */
  document.addEventListener('submit', function (e) {
    var f = e.target, s = e.submitter;
    if (f._ok) { f._ok = false; return; }
    var m = (s && s.dataset && s.dataset.confirm) || (f.dataset && f.dataset.confirm);
    if (!m) return;
    e.preventDefault();
    confirmDialog(m).then(function (yes) {
      if (!yes) return;
      f._ok = true;
      if (f.requestSubmit) { try { f.requestSubmit(s || undefined); } catch (err) { f.requestSubmit(); } }
      else f.submit();
    });
  });

  /* ---------- .alert boxes become toasts (no more layout jumps) ---------- */
  function fromAlert(a) {
    if (!a || a.hasAttribute('data-keep')) return;
    var txt = a.textContent.trim();
    if (a.hidden || !txt) { a._tt = ''; return; }
    if (a._tt === txt) return;
    a._tt = txt;
    var frag = document.createDocumentFragment();
    [].forEach.call(a.childNodes, function (n) { frag.appendChild(n.cloneNode(true)); });
    toast(frag, a.classList.contains('err') ? 'err' : 'ok');
  }
  function scan() { [].forEach.call(document.querySelectorAll('.alert'), fromAlert); }

  function start() {
    scan();
    // Remove ?msg=... from the address bar so a refresh doesn't show the same toast again
    try {
      var u = new URL(location.href);
      if (u.searchParams.has('msg')) {
        u.searchParams.delete('msg');
        history.replaceState(null, '', u.pathname + u.search + u.hash);
      }
    } catch (e) {}
    new MutationObserver(function (list) {
      list.forEach(function (m) {
        var n = m.target.nodeType === 1 ? m.target : m.target.parentElement;
        fromAlert(n && n.closest ? n.closest('.alert') : null);
      });
    }).observe(document.body, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['hidden'] });
  }
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
})();