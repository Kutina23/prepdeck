// Live AI panel for document / lecture processing. Server-Sent Events from api/jobs.php?a=stream, with polling as a fallback.
//   watchJob(jobId, box, onClose)   box = an upload <form> (the form is swapped for the panel) or any element
//   <div data-job="12"></div> and <div data-live-job="12"></div> start by themselves (also inside modals).
(function () {
  var LABEL = {
    start: 'Getting started', reading: 'Reading the document', transcribing: 'Transcribing the lecture', classifying: 'Understanding the document',
    extracting: 'Finding the questions', solving: 'Writing model answers', digesting: 'Summarising the notes', predicting: 'Predicting exam questions & answers',
    flashcards: 'Creating flashcards', guide: 'Writing the study guide', games: 'Setting up games', done: 'Ready to study',
    outlining: 'Finding the course topics', writing: 'Writing lesson notes'
  };
  var FLOW = {
    past_question: ['start', 'reading', 'classifying', 'extracting', 'solving', 'flashcards', 'guide', 'games', 'done'],
    lecture_notes: ['start', 'reading', 'classifying', 'digesting', 'guide', 'predicting', 'flashcards', 'games', 'done'],
    recording: ['start', 'transcribing', 'digesting', 'guide', 'predicting', 'flashcards', 'games', 'done'],
    outline: ['start', 'reading', 'outlining', 'writing', 'done']
  };
  var STAT = [['pages', 'pages'], ['words', 'words'], ['questions', 'questions found'], ['answered', 'answered'], ['predicted', 'exam questions'], ['terms', 'key terms'], ['cards', 'flashcards'], ['guides', 'study guide'], ['games', 'games'], ['topics', 'topics'], ['lessons', 'lesson notes']];
  var ICON = { done: '<svg viewBox="0 0 24 24"><path d="M5 12.5l4.2 4.2L19 7"/></svg>', failed: '<svg viewBox="0 0 24 24"><path d="M7 7l10 10M17 7L7 17"/></svg>' };
  function stepOf(stage) { return stage === 'queued' || stage === 'starting' ? 'start' : stage === 'failed' ? null : stage; }
  function flow(j) { return j.source === 'recording' ? FLOW.recording : j.source === 'outline' ? FLOW.outline : FLOW[j.kind] || FLOW.lecture_notes; }
  function clock(s) { s = Math.max(0, Math.round(s)); return Math.floor(s / 60) + ':' + ('0' + s % 60).slice(-2); }
  function e(s) { return typeof esc === 'function' ? esc(s) : String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); }

  function panel(box) {
    var p = document.createElement('div'); p.className = 'ailive'; p.setAttribute('data-state', 'queued');
    p.innerHTML = '<div class="aihead"><span class="aiorb" aria-hidden="true"><i></i></span><div class="aiti"><b data-ai="headline">PrepDeck AI is getting ready</b><small data-ai="title"></small></div><div class="aimeta"><b data-ai="pct">0%</b><small data-ai="clock">0:00</small></div></div>'
      + '<div class="aibar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-label="Processing progress"><i></i></div>'
      + '<ol class="aisteps" data-ai="steps"></ol><div class="aistats" data-ai="stats"></div>'
      + '<div class="aifeed" data-ai="feed" aria-live="polite" aria-label="What PrepDeck AI is doing"></div><div class="aiend" data-ai="end" hidden></div>';
    if (box.tagName === 'FORM') { Array.prototype.forEach.call(box.children, function (c) { c.hidden = true; }); box.appendChild(p); }
    else { box.innerHTML = ''; box.appendChild(p); }
    box.classList.add('has-ailive');
    var sh = box.closest('.sheet'); if (sh) { sh.classList.add('wide'); var t = document.getElementById('mt'); if (t) t.textContent = 'PrepDeck AI is working'; }
    return p;
  }

  window.watchJob = function (id, box, onClose) {
    if (!box || box.dataset.watching === String(id)) return; box.dataset.watching = String(id);
    var p = panel(box), q = function (k) { return p.querySelector('[data-ai="' + k + '"]'); };
    var t0 = Date.now(), last = null, finished = false, kicked = false, es = null, poller = null, shown = 0;
    var tick = setInterval(function () { if (!finished) q('clock').textContent = clock((Date.now() - t0) / 1000); }, 1000);

    function render(j) {
      last = j; var st = j.status, pct = st === 'done' ? 100 : Math.max(st === 'queued' ? 2 : 4, j.progress || 0);
      p.setAttribute('data-state', st);
      q('pct').textContent = pct + '%'; p.querySelector('.aibar i').style.width = pct + '%'; p.querySelector('.aibar').setAttribute('aria-valuenow', pct);
      q('title').textContent = (j.title || '') + (j.kind ? ' · ' + (j.source === 'outline' ? 'course outline' : j.kind === 'past_question' ? 'exam paper' : (j.source === 'recording' ? 'lecture recording' : 'notes')) : '');
      q('headline').textContent = st === 'done' ? (j.source === 'outline' ? 'All done. Your lesson notes are ready' : 'All done. Your study material is ready') : st === 'failed' ? 'Processing stopped' : st === 'queued' ? 'Waiting for PrepDeck AI to start…' : (LABEL[stepOf(j.stage)] || 'Working') + '…';
      if (j.started && st !== 'queued') { var s0 = Date.parse(j.started.replace(' ', 'T') + 'Z'), n0 = Date.parse(j.now.replace(' ', 'T') + 'Z'); if (s0 && n0) t0 = Date.now() - (n0 - s0); }
      // steps
      var f = flow(j), cur = st === 'done' ? f.length : Math.max(0, f.indexOf(stepOf(j.stage) || f[0]));
      if (st === 'failed') { var lastStage = (j.events || []).filter(function (x) { return x.stage && x.stage !== 'failed'; }).pop(); cur = Math.max(0, f.indexOf(stepOf(lastStage ? lastStage.stage : 'start'))); }
      q('steps').innerHTML = f.map(function (k, i) {
        var c = i < cur ? 'done' : i === cur ? (st === 'failed' ? 'failed' : 'now') : 'todo';
        if (k === 'done' && st === 'done') c = 'done';
        return '<li class="' + c + '"><span class="sd">' + (c === 'done' ? ICON.done : c === 'failed' ? ICON.failed : '') + '</span><span>' + LABEL[k] + '</span></li>';
      }).join('');
      // counters
      var s = j.stats || {};
      q('stats').innerHTML = STAT.filter(function (x) { return s[x[0]]; }).map(function (x) { return '<span class="aichip"><b>' + Number(s[x[0]]).toLocaleString() + '</b> ' + x[1] + '</span>'; }).join('');
      // feed: append only new lines so it reads like a live log
      var ev = j.events || [], feed = q('feed'), first = ev.length ? ev[0].t : 0;
      if (ev.length < shown) { feed.innerHTML = ''; shown = 0; }
      ev.slice(shown).forEach(function (x) {
        var d = document.createElement('div'); d.className = 'aiev ' + (x.level || '');
        d.innerHTML = '<time>' + clock(x.t - first) + '</time><div><p>' + e(x.msg) + '</p>' + (x.items && x.items.length ? '<ul>' + x.items.map(function (it) { return '<li>' + e(it) + '</li>'; }).join('') + '</ul>' : '') + '</div>';
        feed.appendChild(d);
      });
      shown = ev.length;
      var prev = feed.querySelector('.aiev.now'); if (prev) prev.classList.remove('now');
      if (st === 'running' || st === 'queued') { var l = feed.lastElementChild; if (l) l.classList.add('now'); }
      feed.scrollTop = feed.scrollHeight;
      // nothing has picked the job up: run it from this page (hosts without background workers)
      if (st === 'queued' && !kicked && Date.now() - t0 > 6000 && typeof api === 'function') { kicked = true; api('jobs.php?a=run&id=' + id, {}).catch(function () {}); }
      if (st === 'done' || st === 'failed') end(j);
    }

    function end(j) {
      if (finished) return; finished = true; clearInterval(tick); stop();
      var box2 = q('end'), s = j.stats || {}, html = '';
      if (j.status === 'done') {
        html = '<a class="btn" href="' + e(j.link) + '">' + (j.source === 'outline' ? 'Open the outline' : 'Open study material') + '</a>';
        if (s.deck && s.games) html += '<a class="btn line" href="' + e(PDBASE + 'dashboard/games.php?deck=' + s.deck) + '">Play games</a>';
        if (j.review) html += '<a class="btn line" href="' + e(j.review) + '">Review AI answers</a>';
      } else {
        html = '<p class="aierr">' + e(j.message || 'Something went wrong.') + '</p><button type="button" class="btn" data-ai="retry">Try again</button>';
      }
      html += '<button type="button" class="btn line" data-ai="close">' + (j.status === 'done' ? 'Done' : 'Close') + '</button>';
      box2.innerHTML = html; box2.hidden = false;
      var r = box2.querySelector('[data-ai=retry]');
      if (r) r.onclick = function () { r.disabled = true; api('jobs.php?a=retry&id=' + id, {}).then(function () { box.dataset.watching = ''; window.watchJob(id, box, onClose); }).catch(function (x) { r.disabled = false; if (window.toast) toast(x.message); }); };
      box2.querySelector('[data-ai=close]').onclick = function () { if (onClose) onClose(j); else location.reload(); };
      if (window.toast && document.hidden !== true) toast(j.status === 'done' ? 'PrepDeck AI finished: ' + (j.title || 'your document') : 'Processing stopped');
    }

    function stop() { if (es) { es.close(); es = null; } if (poller) { clearInterval(poller); poller = null; } }
    function poll() {
      if (poller || finished) return;
      var go = function () { api('jobs.php?a=status&id=' + id).then(function (r) { render(r.job); }).catch(function () {}); };
      go(); poller = setInterval(go, 2000);
    }
    if (window.EventSource) {
      es = new EventSource(PDBASE + 'api/jobs.php?a=stream&id=' + id);
      es.onmessage = function (m) { try { render(JSON.parse(m.data)); } catch (x) {} };
      es.onerror = function () { if (es) es.close(); es = null; if (!finished) poll(); };
      setTimeout(function () { if (!last && !finished) poll(); }, 4000);   // stream blocked by a proxy: fall back
    } else poll();
    // keep checking for the "run it from the page" fallback even while the stream is quiet
    var kickCheck = setInterval(function () { if (finished) return clearInterval(kickCheck); if (last && last.status === 'queued') render(last); }, 2000);
    return p;
  };

  function scan(root) {
    (root || document).querySelectorAll('[data-job]:not(form), [data-live-job]').forEach(function (b) {
      var id = +(b.dataset.liveJob || b.dataset.job); if (id) window.watchJob(id, b, b.dataset.onclose === 'none' ? function () {} : null);
    });
  }
  scan();
  document.addEventListener('sheetopen', function () { scan(document.getElementById('mb')); });
})();
