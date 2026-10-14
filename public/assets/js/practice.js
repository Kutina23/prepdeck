// Practice exam: one question at a time, optional countdown, reveal the model answer, mark yourself, score + review at the end.
(function () {
  var box = document.getElementById('practice'); if (!box) return;
  var data = JSON.parse(document.getElementById('pdata').textContent), setup = document.getElementById('psetup'), run = document.getElementById('prun');
  var qs = [], i = 0, marks = [], mine = [], t0 = 0, limit = 0, timer = null, over = false;
  var e = function (s) { return esc(s); }, fmt = function (s) { s = Math.max(0, Math.round(s)); return Math.floor(s / 60) + ':' + ('0' + s % 60).slice(-2); };
  var nl = function (s) { return e(s).replace(/\n/g, '<br>'); };

  setup.addEventListener('submit', function (ev) {
    ev.preventDefault();
    qs = data.questions.slice();
    if (setup.shuffle.checked) for (var n = qs.length - 1; n > 0; n--) { var j = Math.floor(Math.random() * (n + 1)), t = qs[n]; qs[n] = qs[j]; qs[j] = t; }
    qs = qs.slice(0, +setup.count.value); marks = qs.map(function () { return null; }); mine = qs.map(function () { return ''; });
    limit = +setup.minutes.value * 60; t0 = Date.now(); i = 0; over = false;
    setup.hidden = true; run.hidden = false; show();
    timer = setInterval(tick, 1000); tick();
    window.addEventListener('beforeunload', guard);
  });
  function guard(ev) { if (!over) { ev.preventDefault(); ev.returnValue = ''; } }
  function elapsed() { return (Date.now() - t0) / 1000; }
  function tick() {
    var c = document.getElementById('pclock'); if (!c) return;
    if (limit) { var left = limit - elapsed(); c.textContent = fmt(left) + ' left'; c.classList.toggle('low', left < 60); if (left <= 0) { if (window.toast) toast('Time is up.'); finish(); } }
    else c.textContent = fmt(elapsed());
  }

  function show() {
    var q = qs[i], done = marks.filter(function (m) { return m !== null; }).length;
    run.innerHTML = '<div class="pxhead"><span class="mono">Question ' + (i + 1) + ' of ' + qs.length + '</span><span class="mono" id="pclock"></span></div>'
      + '<div class="progressbar"><i style="width:' + Math.round(100 * done / qs.length) + '%"></i></div>'
      + '<div class="pxq"><div class="tagrow">' + (q.kind === 'predicted' ? '<span class="pill plus">Likely exam question</span>' : '') + (q.topic ? '<span class="pill free">' + e(q.topic) + '</span>' : '') + (q.marks ? '<span class="pill free">' + q.marks + ' marks</span>' : '') + '</div>'
      + '<h2>' + (q.n ? e(q.n) + '. ' : '') + nl(q.q) + '</h2></div>'
      + '<div class="field"><label for="pmine">Your answer (only you see this)</label><textarea class="in" id="pmine" rows="5" placeholder="Write or outline your answer, then reveal the model answer.">' + e(mine[i]) + '</textarea></div>'
      + '<div class="pxans" id="pans" hidden><b>Model answer</b><div>' + nl(q.a) + '</div></div>'
      + '<div class="rowact" id="pact"><button class="btn" type="button" id="preveal">Reveal model answer</button><button class="btn line" type="button" id="pskip">' + (i < qs.length - 1 ? 'Skip' : 'Finish') + '</button></div>'
      + '<div class="rowact" id="pmark" hidden><span class="note">How did you do?</span><button class="btn sm" type="button" data-m="1">Got it</button><button class="btn sm line" type="button" data-m="0.5">Partly</button><button class="btn sm danger" type="button" data-m="0">Missed it</button></div>'
      + '<div class="rowact pxnav">' + (i ? '<button class="btn sm line" type="button" id="pprev">Back</button>' : '') + '<span class="note">' + done + ' marked</span><button class="btn sm line" type="button" id="pend">End exam</button></div>';
    tick();
    var ta = document.getElementById('pmine'); ta.addEventListener('input', function () { mine[i] = ta.value; });
    document.getElementById('preveal').onclick = reveal;
    document.getElementById('pskip').onclick = function () { next(); };
    if (marks[i] !== null) reveal();
    document.getElementById('pmark').addEventListener('click', function (ev) { var b = ev.target.closest('[data-m]'); if (!b) return; marks[i] = +b.dataset.m; next(); });
    var pv = document.getElementById('pprev'); if (pv) pv.onclick = function () { i--; show(); };
    document.getElementById('pend').onclick = function () {
      var left = marks.filter(function (m) { return m === null; }).length;
      (left && window.confirmDialog ? confirmDialog(left + ' question' + (left === 1 ? ' is' : 's are') + ' not marked yet and will count as missed.', { title: 'End the exam?', ok: 'End exam' }) : Promise.resolve(true)).then(function (ok) { if (ok) finish(); });
    };
    ta.focus();
  }
  function reveal() { document.getElementById('pans').hidden = false; document.getElementById('pact').hidden = true; document.getElementById('pmark').hidden = false; }
  function next() { if (i < qs.length - 1) { i++; show(); window.scrollTo({ top: box.offsetTop - 20, behavior: 'smooth' }); } else finish(); }

  function finish() {
    if (over) return; over = true; clearInterval(timer); window.removeEventListener('beforeunload', guard);
    var secs = Math.round(limit ? Math.min(limit, elapsed()) : elapsed()), score = marks.reduce(function (s, m) { return s + (m || 0); }, 0), pc = Math.round(100 * score / qs.length);
    var verdict = pc >= 80 ? 'Excellent. You are exam ready on this one.' : pc >= 60 ? 'Good work. Review the ones you missed and try again.' : pc >= 40 ? 'Getting there. Read the study guide, then retry.' : 'Keep going. Study the model answers below, then try again.';
    run.innerHTML = '<div class="pxresult"><div class="pxscore ' + (pc >= 70 ? 'ok' : pc >= 40 ? 'mid' : 'low') + '"><b>' + pc + '%</b><small>' + score + ' / ' + qs.length + '</small></div><div><h2>' + verdict + '</h2><p class="note">Time ' + fmt(secs) + ' · ' + marks.filter(function (m) { return m === 1; }).length + ' got it · ' + marks.filter(function (m) { return m === 0.5; }).length + ' partly · ' + marks.filter(function (m) { return !m; }).length + ' missed or skipped</p>'
      + '<div class="rowact" style="margin-top:12px"><button class="btn" type="button" id="pagain">Try again</button><a class="btn line" href="' + PDBASE + 'dashboard/practice.php">Other exams</a></div><p class="note" id="psaved"></p></div></div>'
      + '<h2 style="margin-top:8px">Review</h2><div class="list">' + qs.map(function (q, k) {
        var m = marks[k], tag = m === 1 ? '<span class="pill ok">Got it</span>' : m === 0.5 ? '<span class="pill wait">Partly</span>' : '<span class="pill bad">' + (m === 0 ? 'Missed' : 'Skipped') + '</span>';
        return '<details class="rowx"' + (m === 1 ? '' : ' open') + '><summary><b>' + (k + 1) + '. ' + e(q.q.length > 110 ? q.q.slice(0, 110) + '…' : q.q) + '</b> ' + tag + '</summary>' + (mine[k] ? '<p class="note" style="margin-top:8px"><b>You wrote:</b> ' + nl(mine[k]) + '</p>' : '') + '<div class="pxans"><b>Model answer</b><div>' + nl(q.a) + '</div></div></details>';
      }).join('') + '</div>';
    document.getElementById('pagain').onclick = function () { run.hidden = true; setup.hidden = false; };
    var fd = new FormData(); fd.append('type', data.type); fd.append('id', data.id); fd.append('total', qs.length); fd.append('correct', Math.round(score)); fd.append('seconds', secs);
    fetch(PDBASE + box.dataset.action, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf() }, body: fd })
      .then(function (r) { return r.json(); }).then(function (r) { document.getElementById('psaved').textContent = r.ok ? 'Saved to your progress.' : (r.error || ''); }).catch(function () {});
  }
})();
