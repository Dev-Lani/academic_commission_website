// Login + Register: "Show / Hide" button on password fields.
document.querySelectorAll('.ap-show').forEach(function (b) {
  var inp = document.getElementById(b.dataset.for);
  if (!inp) return;
  b.addEventListener('click', function () {
    var show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    b.textContent = show ? 'Hide' : 'Show';
    b.setAttribute('aria-pressed', String(show));
    inp.focus();
  });
}); 