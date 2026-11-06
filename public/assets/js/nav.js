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
