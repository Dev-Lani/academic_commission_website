// Forum filter bar: menus close properly, type search, custom budget, calendar (max 30 days), auto-submit for sort.
(function () {
  var ff = document.getElementById('ff');
  if (!ff) return;

  /* ---- dropdowns: one open at a time, close on outside click or Esc ---- */
  var menus = [].slice.call(ff.querySelectorAll('details.ff-dd'));
  function closeAll(except) { menus.forEach(function (d) { if (d !== except) d.removeAttribute('open'); }); }
  menus.forEach(function (d) { d.addEventListener('toggle', function () { if (d.open) closeAll(d); }); });
  document.addEventListener('click', function (e) { if (!e.target.closest('details.ff-dd')) closeAll(null); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(null); });

  /* ---- type search ---- */
  var ts = document.getElementById('type-q');
  if (ts) ts.addEventListener('input', function () {
    var q = ts.value.trim().toLowerCase();
    ff.querySelectorAll('[data-type]').forEach(function (l) { l.hidden = !!q && l.dataset.type.toLowerCase().indexOf(q) < 0; });
    ff.querySelectorAll('[data-group]').forEach(function (g) { g.hidden = !g.querySelector('[data-type]:not([hidden])'); });
  });


  /* ---- sort submits right away ---- */
  ff.querySelectorAll('input[name="sort"]').forEach(function (r) {
    r.addEventListener('change', function () { ff.requestSubmit ? ff.requestSubmit() : ff.submit(); });
  });

  /* ---- keep the URL clean: do not send empty fields ---- */
  ff.addEventListener('submit', function () {
    [].forEach.call(ff.elements, function (el) {
      if (el.name && el.type !== 'submit' && el.value === '') el.disabled = true;
    });
  });
  window.addEventListener('pageshow', function () {
    [].forEach.call(ff.elements, function (el) { el.disabled = false; });
  });

  /* ---- calendar ---- */
  var cal = document.getElementById('cal');
  if (!cal) return;
  var MAX_DAYS = 30, MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
      SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function fmt(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parse(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function add(d, n) { return new Date(d.getFullYear(), d.getMonth(), d.getDate() + n); }
  function label(s) { var d = parse(s); return SHORT[d.getMonth()] + ' ' + d.getDate(); }

  var today = parse(cal.dataset.today),
      fromI = document.getElementById('ff-from'), toI = document.getElementById('ff-to'), dueI = document.getElementById('ff-due'),
      title = document.getElementById('cal-title'), txt = document.getElementById('cal-txt'), go = document.getElementById('cal-go'),
      prev = document.getElementById('cal-prev'), next = document.getElementById('cal-next'),
      from = fromI.value, to = toI.value,
      minM = new Date(today.getFullYear(), today.getMonth(), 1),
      maxM = new Date(today.getFullYear(), today.getMonth() + 2, 1),
      m = from ? parse(from) : today;
  m = new Date(m.getFullYear(), m.getMonth(), 1);

  function status() {
    if (!from) txt.textContent = 'Pick a start date.';
    else if (!to) txt.textContent = label(from) + ' · now pick an end date (up to 30 days).';
    else {
      var n = Math.round((parse(to) - parse(from)) / 864e5) + 1;
      txt.textContent = label(from) + (from === to ? '' : ' – ' + label(to)) + ' · ' + n + (n === 1 ? ' day' : ' days') + '. Up to 30 days from the start date.';
    }
    go.disabled = !from;
  }

  function render() {
    title.textContent = MONTHS[m.getMonth()] + ' ' + m.getFullYear();
    prev.disabled = m <= minM;
    next.disabled = m >= maxM;
    cal.textContent = '';
    ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'].forEach(function (w) {
      var e = document.createElement('em'); e.textContent = w; cal.appendChild(e);
    });
    for (var i = 0; i < m.getDay(); i++) cal.appendChild(document.createElement('span'));
    var last = new Date(m.getFullYear(), m.getMonth() + 1, 0).getDate(), picking = from && !to;
    for (var day = 1; day <= last; day++) {
      var d = new Date(m.getFullYear(), m.getMonth(), day), s = fmt(d), b = document.createElement('button'), cls = 'ff-day';
      b.type = 'button'; b.textContent = day; b.dataset.d = s;
      if (d < today || (picking && d > add(parse(from), MAX_DAYS))) b.disabled = true;
      if (s === fmt(today)) cls += ' t';
      if (from && s === from) cls += ' s';
      if (to && s === to) cls += ' e';
      if (from && to && s > from && s < to) cls += ' r';
      b.className = cls;
      cal.appendChild(b);
    }
  }

  cal.addEventListener('click', function (e) {
    var b = e.target.closest('.ff-day');
    if (!b || b.disabled) return;
    var s = b.dataset.d;
    if (!from || to || s < from) { from = s; to = ''; }   // new start
    else to = s;                                          // end
    fromI.value = from; toI.value = to; dueI.value = '';
    status(); render();
  });
  prev.addEventListener('click', function () { m = new Date(m.getFullYear(), m.getMonth() - 1, 1); render(); });
  next.addEventListener('click', function () { m = new Date(m.getFullYear(), m.getMonth() + 1, 1); render(); });
  go.addEventListener('click', function () { if (from && !to) toI.value = from; }); // one day only

  status(); render();
})();