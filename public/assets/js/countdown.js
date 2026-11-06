document.querySelectorAll('[data-deadline]').forEach(function (el) {
  var end = new Date(el.dataset.deadline).getTime();
  var prefix = el.dataset.prefix || '';
  var sec = !!el.dataset.sec;
  function tick() {
    var s = Math.floor((end - Date.now()) / 1000);
    if (s <= 0) { el.textContent = 'Time is up'; return; }
    var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600),
        m = Math.floor(s % 3600 / 60), x = s % 60;
    el.textContent = prefix + (d ? d + 'd ' : '') + h + 'h ' + m + 'm ' + (sec ? x + 's ' : '') + 'left';
  }
  tick();
  setInterval(tick, sec ? 1000 : 30000);
});

document.querySelectorAll('[data-since]').forEach(function (el) {
  var start = new Date(el.dataset.since).getTime();
  function tick() {
    var s = Math.max(0, Math.floor((Date.now() - start) / 1000));
    var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600),
        m = Math.floor(s % 3600 / 60), x = s % 60;
    el.textContent = (d ? d + 'd ' : '') + h + 'h ' + m + 'm ' + x + 's';
  }
  tick();
  setInterval(tick, 1000);
});