// Exam countdown + auto-submit. Activates when #exam-timer exists.
(function () {
  var el = document.getElementById('exam-timer');
  var form = document.getElementById('exam-form');
  if (!el || !form) return;
  var remain = parseInt(el.dataset.seconds || '0', 10);
  var deadline = Date.now() + remain * 1000;
  var submitted = false;
  function fmt(s) {
    s = Math.max(0, s);
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
    return (h > 0 ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(x).padStart(2, '0');
  }
  function doSubmit() {
    if (submitted) return;
    submitted = true;
    clearInterval(iv);
    alert('Time is up. Submitting your exam.');
    form.submit();
  }
  function checkSubmit() {
    if (submitted) return;
    var left = Math.ceil((deadline - Date.now()) / 1000);
    el.textContent = '\u23F3 ' + fmt(left);
    if (left <= 0) doSubmit();
  }
  var iv = setInterval(checkSubmit, 1000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) checkSubmit();
  });
  window.addEventListener('focus', checkSubmit);
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) checkSubmit();
  });
})();
