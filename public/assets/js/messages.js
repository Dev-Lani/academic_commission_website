// Messages page: keeps the unread badges, previews and times in the list fresh.
(function () {
  var list = document.getElementById('convo-list');
  if (!list) return;
  var cur = parseInt(list.dataset.cur, 10) || 0;

  function load() {
    return fetch('chat_list.php', { headers: { 'X-Requested-With': 'fetch' }, cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .catch(function () { return null; });
  }
  function refresh() {
    if (document.hidden) return;
    load().then(function (d) {
      if (!d) return;
      d.convos.forEach(function (c) {
        var row = list.querySelector('[data-id="' + c.id + '"]');
        if (!row) return;
        var unread = c.id === cur ? 0 : c.unread; // ang bukas na chat ay binabasa na ng chat.js
        row.querySelector('.cv-prev').textContent = c.prev;
        if (c.time) row.querySelector('.cv-time').textContent = c.time;
        row.classList.toggle('unread', unread > 0);
        var b = row.querySelector('.badge');
        if (unread > 0) {
          if (!b) { b = document.createElement('span'); b.className = 'badge'; row.appendChild(b); }
          b.textContent = unread;
        } else if (b) {
          b.remove();
        }
      });
    });
  }
  setInterval(refresh, 7000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
})();


// Order side panel: toggle, remembered on desktop. On phones it opens as a sheet and starts closed.
(function () {
  var root = document.documentElement,
      btn = document.getElementById('op-toggle'),
      x = document.getElementById('op-close');
  if (!btn) return;
  function set(on) {
    root.classList.toggle('op-open', on);
    btn.setAttribute('aria-expanded', String(on));
    if (matchMedia('(min-width:821px)').matches) {
      try { localStorage.setItem('acw.op', on ? '1' : '0'); } catch (e) {}
    }
  }
  btn.setAttribute('aria-expanded', String(root.classList.contains('op-open')));
  btn.addEventListener('click', function () { set(!root.classList.contains('op-open')); });
  if (x) x.addEventListener('click', function () { set(false); btn.focus(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && root.classList.contains('op-open')) set(false);
  });
})();