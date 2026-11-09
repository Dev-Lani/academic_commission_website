(function () {
  var root = document.getElementById('mb');
  if (!root) return;
  var csrf = root.dataset.csrf,
      btn = root.querySelector('.mb-btn'),
      badge = root.querySelector('.mb-badge'),
      pop = root.querySelector('.mb-pop'),
      toast = root.querySelector('.mb-toast'),
      state = 'closed', cur = null, last = 0, convos = [],
      total = parseInt(root.dataset.unread, 10) || 0,
      threadTimer = null, toastTimer = null, tick = 0, busyThread = false;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  function api(url, opt) {
    opt = opt || {};
    opt.headers = { 'X-Requested-With': 'fetch' };
    opt.cache = 'no-store';
    return fetch(url, opt).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; });
  }
  function setBadge(n) {
    badge.textContent = n;
    badge.hidden = !n;
    btn.setAttribute('aria-label', n ? 'Messages, ' + n + ' unread' : 'Messages');
  }
  function initial(s) { return (s || '?').charAt(0).toUpperCase(); }

  function loadList() {
    return api('chat_list.php').then(function (d) {
      if (!d) return;
      convos = d.convos;
      if (state === 'closed' && d.unread > total) {
        var c = convos.filter(function (x) { return x.unread > 0; })[0];
        if (c) showToast(c);
      }
      total = d.unread;
      setBadge(total);
      if (state === 'list') renderList();
    });
  }

  function showToast(c) {
    toast.textContent = '';
    toast.appendChild(el('b', null, c.name));
    toast.appendChild(el('span', null, c.prev));
    toast.hidden = false;
    toast.onclick = function () { toast.hidden = true; openThread(c); };
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.hidden = true; }, 6000);
  }

  function header(titleNode, extra) {
    var h = el('div', 'mb-head');
    h.appendChild(titleNode);
    if (extra) h.appendChild(extra);
    var x = el('button', 'mb-x', '—');
    x.type = 'button'; x.setAttribute('aria-label', 'Close messages');
    x.onclick = close;
    h.appendChild(x);
    return h;
  }

  function renderList() {
    var keepQ = pop.querySelector('.mb-search') ? pop.querySelector('.mb-search').value : '';
    pop.textContent = '';
    var more = el('a', 'mb-link', 'Open full page');
    more.href = 'messages.php';
    pop.appendChild(header(el('b', 'mb-title', 'Messages'), more));
    var s = el('input', 'mb-search');
    s.type = 'text'; s.placeholder = 'Search chats'; s.setAttribute('aria-label', 'Search chats'); s.value = keepQ;
    pop.appendChild(s);
    var list = el('div', 'mb-list');
    pop.appendChild(list);
    function draw() {
      list.textContent = '';
      var q = s.value.trim().toLowerCase(), n = 0;
      convos.forEach(function (c) {
        if (q && (c.name + ' ' + c.title).toLowerCase().indexOf(q) < 0) return;
        n++;
        var r = el('button', 'mb-row' + (c.unread ? ' unread' : ''));
        r.type = 'button';
        r.appendChild(el('span', 'mb-av', initial(c.name)));
        var m = el('span', 'mb-meta');
        var top = el('span', 'mb-top');
        top.appendChild(el('b', null, c.name));
        top.appendChild(el('time', null, c.time));
        m.appendChild(top);
        m.appendChild(el('small', null, c.title));
        m.appendChild(el('small', 'mb-prev', c.prev));
        r.appendChild(m);
        if (c.unread) r.appendChild(el('span', 'badge', c.unread));
        r.onclick = function () { openThread(c); };
        list.appendChild(r);
      });
      if (!n) list.appendChild(el('p', 'mb-empty', convos.length ? 'No chats match.' : 'No conversations yet. A chat opens once a commissioner agrees to an order.'));
    }
    s.oninput = draw;
    draw();
  }

  /* ---------- thread ---------- */
  function openThread(c) {
    stopThread();
    state = 'thread'; cur = c; last = 0;
    toast.hidden = true;
    pop.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    pop.textContent = '';

    var back = el('button', 'mb-back', '‹');
    back.type = 'button'; back.setAttribute('aria-label', 'Back to chats');
    back.onclick = function () { stopThread(); openList(); };
    var who = el('span', 'mb-who');
    who.appendChild(el('b', null, c.name));
    who.appendChild(el('small', null, c.title));
    var wrap = el('span', 'mb-headl');
    wrap.appendChild(back); wrap.appendChild(who);
    var link = el('a', 'mb-link', 'View order');
    link.href = 'order.php?id=' + c.id;
    pop.appendChild(header(wrap, link));

    var log = el('ul', 'mb-log');
    log.setAttribute('aria-live', 'polite');
    pop.appendChild(log);
    var form = null, ta = null;
    if (c.open) {
      form = el('form', 'mb-form');
      ta = el('textarea');
      ta.rows = 1; ta.maxLength = 2000; ta.placeholder = 'Write a message…'; ta.setAttribute('aria-label', 'Message');
      var send = el('button', 'btn sm', 'Send');
      form.appendChild(ta); form.appendChild(send);
      pop.appendChild(form);
    } else {
      pop.appendChild(el('p', 'mb-closed', 'This chat is closed. The log is kept for review.'));
    }

    function add(m) {
      if (log.querySelector('[data-id="' + m.id + '"]')) return;
      var li = el('li', 'mb-msg ' + (m.mine ? 'mine' : 'theirs'));
      li.dataset.id = m.id;
      li.appendChild(el('div', 'mb-bubble', m.body));
      li.appendChild(el('small', null, (m.mine ? 'You' : m.name) + ' · ' + m.time));
      log.appendChild(li);
      last = Math.max(last, m.id);
    }
    function poll() {
      if (busyThread || document.hidden || state !== 'thread' || cur !== c) return Promise.resolve();
      busyThread = true;
      return api('chat.php?order_id=' + c.id + '&after=' + last).then(function (d) {
        busyThread = false;
        if (!d || state !== 'thread' || cur !== c) return;
        var stick = log.scrollHeight - log.scrollTop - log.clientHeight < 80;
        d.messages.forEach(add);
        if (d.messages.length && stick) log.scrollTop = log.scrollHeight;
        if (!d.open && form) form.hidden = true;
      });
    }
    function sendNow() {
      var v = ta.value.trim();
      if (!v) return;
      var fd = new FormData();
      fd.append('csrf', csrf); fd.append('order_id', c.id); fd.append('body', v);
      send.disabled = true;
      fetch('chat.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (x) {
          if (!x.ok) { alert(x.d.error || 'Could not send.'); return; }
          ta.value = '';
          return poll().then(function () { log.scrollTop = log.scrollHeight; });
        })
        .catch(function () { alert('Could not send. Check your connection.'); })
        .then(function () { send.disabled = false; ta.focus(); });
    }
    if (form) {
      form.onsubmit = function (e) { e.preventDefault(); sendNow(); };
      ta.onkeydown = function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); sendNow(); }
      };
    }
    poll().then(function () { log.scrollTop = log.scrollHeight; if (ta) ta.focus(); });
    threadTimer = setInterval(poll, 3000);
  }
  function stopThread() { clearInterval(threadTimer); threadTimer = null; busyThread = false; }

  /* ---------- open / close ---------- */
  function openList() {
    state = 'list';
    pop.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    toast.hidden = true;
    renderList();
    loadList();
  }
  function close() {
    stopThread();
    state = 'closed'; cur = null;
    pop.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
    loadList();
  }
  btn.onclick = function () { state === 'closed' ? openList() : close(); };
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && state !== 'closed') close(); });

  loadList();
  setInterval(function () {
    tick++;
    if (document.hidden) return;
    if (state === 'list' || (state === 'closed' && tick % 3 === 0)) loadList();
  }, 7000);
})();
