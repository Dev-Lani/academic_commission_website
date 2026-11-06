(function () {
  var box = document.getElementById('chat');
  if (!box) return;
  var log = document.getElementById('chat-log'),
      empty = document.getElementById('chat-empty'),
      form = document.getElementById('chat-form'),
      order = box.dataset.order,
      last = parseInt(box.dataset.last, 10) || 0,
      busy = false;

  function nearBottom() { return log.scrollHeight - log.scrollTop - log.clientHeight < 80; }
  function toBottom() { log.scrollTop = log.scrollHeight; }

  function add(m) {
    if (log.querySelector('[data-id="' + m.id + '"]')) return;
    var li = document.createElement('li');
    li.className = 'msg ' + (m.mine ? 'mine' : 'theirs');
    li.dataset.id = m.id;
    var b = document.createElement('div'); b.className = 'bubble'; b.textContent = m.body;
    var s = document.createElement('small'); s.textContent = (m.mine ? 'You' : m.name) + ' · ' + m.time;
    li.appendChild(b); li.appendChild(s);
    log.appendChild(li);
    last = Math.max(last, m.id);
  }

  function poll() {
    if (busy || document.hidden) return Promise.resolve();
    busy = true;
    return fetch('chat.php?order_id=' + order + '&after=' + last, { headers: { 'X-Requested-With': 'fetch' }, cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        var stick = nearBottom();
        d.messages.forEach(add);
        if (d.messages.length) { empty.hidden = true; if (stick) toBottom(); }
        if (!d.open && form) { form.hidden = true; }
      })
      .catch(function () {})
      .then(function () { busy = false; });
  }

  toBottom();
  setInterval(poll, 3000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });

  if (!form) return;
  var ta = form.elements.body, btn = form.querySelector('button');

  function send() {
    if (!ta.value.trim()) return;
    btn.disabled = true;
    fetch('chat.php', { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (x) {
        if (!x.ok) { alert(x.d.error || 'Could not send.'); return; }
        ta.value = '';
        return poll().then(toBottom);
      })
      .catch(function () { alert('Could not send. Check your connection.'); })
      .then(function () { btn.disabled = false; ta.focus(); });
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); send(); });
  ta.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); }
  });
})();