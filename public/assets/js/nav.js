// Top bar dropdowns (notifications, account menu).
(function () {
  function closeAll(except) {
    document.querySelectorAll('.dd').forEach(function (d) {
      if (d !== except) {
        d.hidden = true;
        var t = document.querySelector('[data-dd="' + d.id + '"]');
        if (t) t.setAttribute('aria-expanded', 'false');
      }
    });
  }
  document.addEventListener('click', function (e) {
    if (e.target.closest('.dd')) return;
    var b = e.target.closest('[data-dd]');
    if (!b) { closeAll(null); return; }
    var d = document.getElementById(b.dataset.dd);
    closeAll(d);
    d.hidden = !d.hidden;
    b.setAttribute('aria-expanded', String(!d.hidden));
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(null); });
})();


// Sidebar: icon rail on desktop, slide-in drawer on phones.
(function () {
  var root = document.documentElement,
      edge = document.getElementById('sb-edge'),
      burger = document.getElementById('sb-burger'),
      scrim = document.getElementById('sb-scrim');
  if (!edge) return;
  var KEY = 'acw.sb', st = { rail: root.classList.contains('sb-rail'), closed: [] };
  try {
    var s = JSON.parse(localStorage.getItem(KEY) || '{}');
    if (Array.isArray(s.closed)) st.closed = s.closed;
  } catch (e) {}
  function save() { try { localStorage.setItem(KEY, JSON.stringify(st)); } catch (e) {} }
  function phone() { return matchMedia('(max-width:820px)').matches; }

  function showRail() {
    root.classList.toggle('sb-rail', st.rail);
    edge.setAttribute('aria-expanded', String(!st.rail));
    edge.setAttribute('aria-label', st.rail ? 'Expand sidebar' : 'Collapse sidebar');
  }
  function setOpen(o) {
    root.classList.toggle('sb-open', o);
    burger.setAttribute('aria-expanded', String(o));
  }

  edge.addEventListener('click', function () { st.rail = !st.rail; showRail(); save(); });
  burger.addEventListener('click', function () { setOpen(!root.classList.contains('sb-open')); });
  scrim.addEventListener('click', function () { setOpen(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
  document.getElementById('sbw').addEventListener('click', function (e) {
    if (phone() && e.target.closest('a.sb-it')) setOpen(false);
  });
  matchMedia('(max-width:820px)').addEventListener('change', function () { setOpen(false); });

  // collapsible sections
  document.querySelectorAll('.sb-sh').forEach(function (b) {
    var id = b.dataset.sec, list = document.getElementById('sbl-' + id);
    function apply(open) { b.setAttribute('aria-expanded', String(open)); list.hidden = !open; }
    apply(st.closed.indexOf(id) < 0);
    b.addEventListener('click', function () {
      var open = b.getAttribute('aria-expanded') !== 'true';
      apply(open);
      st.closed = st.closed.filter(function (x) { return x !== id; });
      if (!open) st.closed.push(id);
      save();
    });
  });

  showRail();
})();