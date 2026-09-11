// Exam countdown + auto-submit. Activates when #exam-timer exists.
(function () {
  var el = document.getElementById('exam-timer');
  var form = document.getElementById('exam-form');
  if (!el || !form) return;
  var remain = parseInt(el.dataset.seconds || '0', 10);
  function fmt(s) {
    s = Math.max(0, s);
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
    return (h > 0 ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(x).padStart(2, '0');
  }
  var iv = setInterval(function () {
    el.textContent = '⏳ ' + fmt(remain);
    if (remain <= 0) { clearInterval(iv); alert('Time is up. Submitting your exam.'); form.submit(); }
    remain--;
  }, 1000);
})();
