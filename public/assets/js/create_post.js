// New request form: chips, quick picks, live preview and checklist.
(function () {
  var f = document.getElementById('cf');
  if (!f) return;
  var $ = function (id) { return document.getElementById(id); };
  var title = $('title'), pages = $('pages'), dl = $('deadline'), bmin = $('budget_min'), bmax = $('budget_max'), desc = $('description'),
      btn = $('post-btn'), money = function (n) { return '₱' + Number(n).toLocaleString('en-US'); };

  function checked(name) { return f.querySelector('input[name="' + name + '"]:checked'); }
  function fire(el) { el.dispatchEvent(new Event('input', { bubbles: true })); }

  // subjects: show first 8, rest on demand
  var chips = $('subj-chips'), more = $('subj-more');
  more.addEventListener('click', function () {
    chips.classList.toggle('open');
    more.textContent = chips.classList.contains('open') ? 'Show fewer ▴' : 'Show all subjects ▾';
  });

  // pages stepper
  f.querySelectorAll('[data-step]').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = (parseInt(pages.value, 10) || 0) + parseInt(b.dataset.step, 10);
      pages.value = Math.min(500, Math.max(1, v)); fire(pages);
    });
  });
  // deadline quick picks
  f.querySelectorAll('[data-hours]').forEach(function (b) {
    b.addEventListener('click', function () {
      var d = new Date(Date.now() + parseInt(b.dataset.hours, 10) * 3600000);
      dl.value = new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16); fire(dl);
    });
  });
  // budget presets
  f.querySelectorAll('[data-min]').forEach(function (b) {
    b.addEventListener('click', function () { bmin.value = b.dataset.min; bmax.value = b.dataset.max; fire(bmin); });
  });
  // description prompts
  f.querySelectorAll('[data-add]').forEach(function (b) {
    b.addEventListener('click', function () {
      desc.value = (desc.value.trim() ? desc.value.replace(/\s+$/, '') + '\n' : '') + b.dataset.add;
      desc.focus(); fire(desc);
    });
  });

  function update() {
    var t = title.value.trim(), d = desc.value.trim(),
        min = parseInt(bmin.value, 10), max = parseInt(bmax.value, 10),
        pg = parseInt(pages.value, 10), when = dl.value ? new Date(dl.value) : null,
        sub = checked('subject_id'), typ = checked('type');
    $('title-count').textContent = title.value.length + ' / 120';
    $('desc-count').textContent = desc.value.length + ' / 3000';

    var budBad = !isNaN(min) && !isNaN(max) && max < min;
    $('bud-err').hidden = !budBad;
    var ok = {
      title: t.length >= 5 && t.length <= 120,
      subject: !!sub, type: !!typ,
      pages: pg >= 1 && pg <= 500,
      deadline: !!when && when.getTime() > Date.now(),
      budget: min >= 1 && max >= min,
      desc: d.length >= 20
    }, done = 0, all = 0;
    f.querySelectorAll('[data-ck]').forEach(function (row) {
      var v = !!ok[row.dataset.ck]; all++; if (v) done++;
      row.classList.toggle('ok', v);
    });
    $('ck-n').textContent = done + ' of ' + all;
    $('ck-bar').style.width = (done / all * 100) + '%';
    btn.disabled = done < all;

    $('pv-kick').textContent = (sub ? sub.nextElementSibling.textContent : 'Subject') + ' · ' + (typ ? typ.value : 'Type');
    $('pv-title').textContent = t || 'Your title appears here';
    var meta = [];
    if (pg) meta.push(pg + (pg === 1 ? ' page' : ' pages'));
    if (min >= 1 && max >= 1) meta.push(money(min) + ' – ' + money(max));
    if (when && !isNaN(when)) meta.push('Due ' + when.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
    $('pv-meta').textContent = meta.join(' · ');
  }
  f.addEventListener('input', update);
  f.addEventListener('change', update);
  update();
})();
