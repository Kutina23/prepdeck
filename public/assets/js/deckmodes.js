// Learn and Test modes for a flashcard set (dashboard/learn.php, dashboard/test.php). Cards come from api/decks.php.
(function () {
  var $ = function (id) { return document.getElementById(id); };

  /* ---------- shared helpers ---------- */
  function norm(s) {
    return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, ' ').trim().replace(/^(a|an|the) /, '');
  }
  function lev(a, b) {
    if (Math.abs(a.length - b.length) > 3) return 99;
    var p = [], i, j; for (j = 0; j <= b.length; j++) p[j] = j;
    for (i = 1; i <= a.length; i++) { var c = [i]; for (j = 1; j <= b.length; j++) c[j] = Math.min(p[j] + 1, c[j - 1] + 1, p[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1)); p = c; }
    return p[b.length];
  }
  /** Is a typed answer right? Ignores case, accents, punctuation and a leading "the"; allows a small typo on longer answers; "x / y" or "x; y" accepts either. */
  function same(typed, answer) {
    var t = norm(typed); if (!t) return false;
    return String(answer).split(/\s*[\/;]\s*/).concat([answer]).some(function (alt) {
      var b = norm(alt); if (!b) return false; if (t === b) return true;
      var tol = b.length >= 12 ? 2 : (b.length >= 5 ? 1 : 0); return tol > 0 && lev(t, b) <= tol;
    });
  }
  function shuffle(a) { a = a.slice(); for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)), x = a[i]; a[i] = a[j]; a[j] = x; } return a; }
  function load(deck) {
    return api('decks.php?a=cards&id=' + deck).then(function (r) {
      return r.cards.filter(function (c) { return c.back !== null && String(c.front).trim() && String(c.back).trim(); })
        .map(function (c) { return { id: c.id, front: String(c.front).trim(), back: String(c.back).trim() }; });
    });
  }
  /** Up to n other answers that read differently from the right one. */
  function distractors(items, it, side, n) {
    var seen = {}, out = []; seen[norm(it[side])] = 1;
    shuffle(items).forEach(function (o) { var k = norm(o[side]); if (out.length < n && o !== it && !seen[k]) { seen[k] = 1; out.push(o[side]); } });
    return out;
  }
  function clip(s, n) { s = String(s); return s.length > n ? s.slice(0, n - 1) + '…' : s; }
  function save(deck, mode, total, correct, secs) { return api('study.php?a=result', { deck_id: deck, mode: mode, total: total, correct: correct, seconds: secs }).catch(function () { return {}; }); }

  /* ---------- Learn: multiple choice -> written -> mastered; misses come back ---------- */
  var L = $('learn');
  if (L) (function () {
    var deck = +L.dataset.deck, track = L.dataset.track === '1', items = [], queue = [], cur = null, dir = 'back', round = 0, busy = false, started = Date.now();
    var ask = function (it) { return dir === 'back' ? it.front : it.back; }, ans = function (it) { return dir === 'back' ? it.back : it.front; };
    var side = function () { return dir === 'back' ? 'back' : 'front'; };
    var writeable = function (it) { return ans(it).length <= 80; };
    function progress() {
      var m = 0, f = 0, n = 0; items.forEach(function (it) { if (it.lvl >= 2) m++; else if (it.seen) f++; else n++; });
      $('lnMastered').textContent = m; $('lnFamiliar').textContent = f; $('lnNew').textContent = n;
      $('lnBarM').style.width = (100 * m / items.length) + '%'; $('lnBarF').style.width = (100 * f / items.length) + '%';
    }
    function newRound() {
      var left = items.filter(function (it) { return it.lvl < 2; });
      if (!left.length) return finish();
      round++; left.sort(function (a, b) { return b.lvl - a.lvl || (a.order - b.order); });   // familiar terms first, then new ones in a fixed shuffled order
      queue = left.slice(0, 7); next();
    }
    function next() {
      $('lnFb').hidden = true; $('lnFb').className = 'dm-fb'; busy = false;
      cur = queue.shift(); if (!cur) return roundDone();
      var written = cur.lvl >= 1 && writeable(cur);
      $('lnPrompt').textContent = ask(cur);
      $('lnKind').textContent = written ? 'Write the ' + (dir === 'back' ? 'definition' : 'term') : (cur.lvl ? 'Again: choose the answer' : 'Choose the answer');
      $('lnChoices').hidden = written; $('lnWrite').hidden = !written;
      if (written) { $('lnInput').value = ''; $('lnInput').disabled = false; $('lnInput').focus(); return; }
      var opts = shuffle([ans(cur)].concat(distractors(items, cur, side(), 3)));
      $('lnChoices').innerHTML = opts.map(function (o, i) { return '<button type="button" class="dm-opt" data-v="' + i + '"><kbd>' + (i + 1) + '</kbd><span>' + esc(clip(o, 220)) + '</span></button>'; }).join('');
      $('lnChoices').querySelectorAll('.dm-opt').forEach(function (b, i) { b.dataset.ok = norm(opts[i]) === norm(ans(cur)) ? '1' : ''; });
    }
    function answered(ok, extra) {
      if (busy) return; busy = true; var it = cur;
      if (!it.firstDone) { it.firstDone = true; it.firstOk = ok; if (track) api('study.php?a=rate', { card_id: it.id, rating: ok ? 3 : 1 }).catch(function () {}); }
      it.seen = true; it.prev = it.lvl;
      if (ok) it.lvl++; else { it.lvl = 0; if (queue.indexOf(it) < 0) queue.push(it); }   // a miss comes back later in this round
      progress();
      var fb = $('lnFb'); fb.hidden = false; fb.className = 'dm-fb ' + (ok ? 'ok' : 'bad');
      fb.innerHTML = ok ? '<b>' + (['Nice!', 'Correct!', 'You got it!', 'Great work!'][Math.floor(Math.random() * 4)]) + '</b>' + (it.lvl === 1 && writeable(it) ? '<span>Next time you will write this one.</span>' : '')
        : '<b>Not quite. Study this one:</b><span class="dm-right">' + esc(ans(it)) + '</span>' + (extra ? '<span class="dm-you">You wrote: ' + esc(extra) + '</span>' : '');
      fb.insertAdjacentHTML('beforeend', '<div class="rowact">' + (!ok && extra ? '<button type="button" class="btn sm line" data-override>I was right</button>' : '') + '<button type="button" class="btn sm" data-next>Continue</button></div>');
      if (ok) setTimeout(function () { if (cur === it && busy) next(); }, 900); else fb.querySelector('[data-next]').focus();
    }
    function roundDone() {
      var m = items.filter(function (it) { return it.lvl >= 2; }).length;
      if (m === items.length) return finish();
      var fb = $('lnFb'); $('lnChoices').hidden = true; $('lnWrite').hidden = true;
      $('lnKind').textContent = 'Round ' + round + ' done'; $('lnPrompt').textContent = m + ' of ' + items.length + ' terms mastered. Keep going.';
      fb.hidden = false; fb.className = 'dm-fb'; fb.innerHTML = '<div class="rowact"><button type="button" class="btn" data-round>Continue to round ' + (round + 1) + '</button></div>';
      fb.querySelector('[data-round]').focus();
    }
    function finish() {
      $('lnCard').hidden = true; $('lnDone').hidden = false;
      var first = items.filter(function (it) { return it.firstOk; }).length;
      $('lnDoneSub').textContent = 'All ' + items.length + ' terms in ' + round + ' round' + (round === 1 ? '' : 's') + '. You knew ' + first + ' straight away.' + (track ? ' They are now scheduled in Smart review.' : '');
      save(deck, 'learn', items.length, first, Math.round((Date.now() - started) / 1000));
    }
    function start() {
      items.forEach(function (it, i) { it.lvl = 0; it.seen = false; it.firstDone = false; it.firstOk = false; it.order = i; });
      items = shuffle(items); items.forEach(function (it, i) { it.order = i; });
      round = 0; started = Date.now(); $('lnCard').hidden = false; $('lnDone').hidden = true; progress(); newRound();
    }
    $('lnChoices').addEventListener('click', function (e) {
      var b = e.target.closest('.dm-opt'); if (!b || busy) return;
      $('lnChoices').querySelectorAll('.dm-opt').forEach(function (x) { x.disabled = true; if (x.dataset.ok) x.classList.add('right'); });
      if (!b.dataset.ok) b.classList.add('wrong');
      answered(!!b.dataset.ok);
    });
    $('lnWrite').addEventListener('submit', function (e) { e.preventDefault(); if (busy) return; var v = $('lnInput').value; if (!v.trim()) return; $('lnInput').disabled = true; answered(same(v, ans(cur)), same(v, ans(cur)) ? '' : v); });
    $('lnSkip').addEventListener('click', function () { if (!busy) { $('lnInput').disabled = true; answered(false); } });
    $('lnFb').addEventListener('click', function (e) {
      if (e.target.closest('[data-next]')) next();
      else if (e.target.closest('[data-round]')) newRound();
      else if (e.target.closest('[data-override]')) {   // the student says their answer was right: count it, and take it out of the repeat list
        var it = cur; it.lvl = it.prev + 1; if (it.firstOk === false && it.firstDone && !it.overridden) { it.firstOk = true; it.overridden = true; }
        var k = queue.indexOf(it); if (k >= 0) queue.splice(k, 1); progress(); next();
      }
    });
    document.addEventListener('keydown', function (e) {
      if (document.body.classList.contains('modal-open')) return;
      if (/^[1-4]$/.test(e.key) && !$('lnChoices').hidden && !busy && e.target.tagName !== 'INPUT') { var b = $('lnChoices').querySelectorAll('.dm-opt')[+e.key - 1]; if (b) b.click(); }
      else if (e.key === 'Enter' && busy && e.target.tagName !== 'BUTTON') { var n = $('lnFb').querySelector('[data-next],[data-round]'); if (n) { e.preventDefault(); n.click(); } }
    });
    $('lnDir').addEventListener('change', function () { dir = this.value; start(); });
    $('lnAgain').addEventListener('click', start);
    load(deck).then(function (c) {
      if (c.length < 2) { $('lnKind').textContent = ''; $('lnPrompt').textContent = 'This set needs at least 2 cards with answers to learn.'; return; }
      items = c; start();
    }).catch(function (e) { $('lnPrompt').textContent = e.message; });
  })();

  /* ---------- Test: build a test from the set, mark it at once ---------- */
  var T = $('test');
  if (T) (function () {
    var deck = +T.dataset.deck, items = [], qs = [], t0 = 0, clock = null;
    function build(f) {
      var n = Math.max(1, Math.min(+f.n || 10, items.length, 50)), types = ['mc', 'tf', 'wr', 'mt'].filter(function (k) { return f[k]; });
      var pick = shuffle(items).slice(0, n), out = [], mtN = 0;
      var dirOf = function () { return f.dir === 'both' ? (Math.random() < .5 ? 'back' : 'front') : f.dir; };
      if (types.indexOf('mt') >= 0) mtN = types.length === 1 ? n : (n >= 8 ? 5 : (n >= 4 ? 4 : 0));
      var mtCards = pick.splice(0, mtN), rest = types.filter(function (k) { return k !== 'mt'; });
      if (!rest.length && pick.length) { mtCards = mtCards.concat(pick); pick = []; }
      pick.forEach(function (it, i) {
        var type = rest[i % rest.length], d = dirOf(), s = d === 'back' ? 'back' : 'front', p = d === 'back' ? 'front' : 'back', q = { type: type, it: it, prompt: it[p], answer: it[s] };
        if (type === 'wr' && q.answer.length > 120) q.type = type = 'mc';   // long answers are not fair to type
        if (type === 'mc') q.opts = shuffle([q.answer].concat(distractors(items, it, s, 3)));
        if (type === 'tf') { var wrong = distractors(items, it, s, 1); q.truth = !wrong.length || Math.random() < .5; q.shown = q.truth ? q.answer : wrong[0]; }
        out.push(q);
      });
      for (var i = 0; i < mtCards.length; i += 5) {   // matching blocks of up to five pairs
        var blk = mtCards.slice(i, i + 5); if (blk.length < 2 && out.length) { out.push({ type: 'wr', it: blk[0], prompt: blk[0].front, answer: blk[0].back }); continue; }
        var d = f.dir === 'front' ? 'front' : 'back', s = d, p = d === 'back' ? 'front' : 'back';
        out.push({ type: 'mt', pairs: blk.map(function (it) { return { prompt: it[p], answer: it[s] }; }), opts: shuffle(blk.map(function (it) { return it[s]; })) });
      }
      return shuffle(out.filter(function (q) { return q.type !== 'mt'; })).concat(out.filter(function (q) { return q.type === 'mt'; }));
    }
    var LBL = { mc: 'Multiple choice', tf: 'True or false', wr: 'Written', mt: 'Matching' }, ABC = 'ABCDE';
    function render() {
      var pts = qs.reduce(function (s, q) { return s + (q.type === 'mt' ? q.pairs.length : 1); }, 0);
      $('tsCount').textContent = qs.length + ' question' + (qs.length === 1 ? '' : 's') + ' · ' + pts + ' mark' + (pts === 1 ? '' : 's');
      $('tsQs').innerHTML = qs.map(function (q, n) {
        var h = '<li class="dm-q" data-i="' + n + '"><p class="dm-qt">' + LBL[q.type] + '</p>';
        if (q.type === 'mc') h += '<p class="dm-qp">' + esc(q.prompt) + '</p><div class="dm-qo">' + q.opts.map(function (o, k) { return '<label class="dm-radio"><input type="radio" name="q' + n + '" value="' + k + '"><span>' + esc(clip(o, 260)) + '</span></label>'; }).join('') + '</div>';
        if (q.type === 'tf') h += '<div class="dm-tf"><p class="dm-qp">' + esc(q.prompt) + '</p><p class="dm-tfa">' + esc(clip(q.shown, 260)) + '</p></div><div class="dm-qo row"><label class="dm-radio"><input type="radio" name="q' + n + '" value="1"><span>True</span></label><label class="dm-radio"><input type="radio" name="q' + n + '" value="0"><span>False</span></label></div>';
        if (q.type === 'wr') h += '<p class="dm-qp">' + esc(q.prompt) + '</p><label class="vh" for="w' + n + '">Your answer</label><input class="in" id="w' + n + '" name="q' + n + '" spellcheck="false" placeholder="Type the answer">';
        if (q.type === 'mt') h += '<p class="dm-qp">Match each item to its answer.</p><ol class="dm-mto">' + q.opts.map(function (o, k) { return '<li><b>' + ABC[k] + '</b> ' + esc(clip(o, 200)) + '</li>'; }).join('') + '</ol><div class="dm-mtq">'
          + q.pairs.map(function (p, k) { return '<label class="dm-mtrow"><span>' + esc(clip(p.prompt, 200)) + '</span><select class="in" name="q' + n + '_' + k + '"><option value="">–</option>' + q.opts.map(function (o, j) { return '<option value="' + j + '">' + ABC[j] + '</option>'; }).join('') + '</select></label>'; }).join('') + '</div>';
        return h + '<div class="dm-qfb" hidden></div></li>';
      }).join('');
    }
    function grade() {
      var fd = new FormData($('tsPaper')), got = 0, total = 0, blank = 0;
      qs.forEach(function (q, n) {
        var li = $('tsQs').querySelector('[data-i="' + n + '"]'), fb = li.querySelector('.dm-qfb'), ok = false, v = fd.get('q' + n);
        if (q.type === 'mt') {
          var right = 0; q.pairs.forEach(function (p, k) {
            var sel = li.querySelector('[name="q' + n + '_' + k + '"]'), j = sel.value, good = j !== '' && norm(q.opts[+j]) === norm(p.answer);
            if (j === '') blank++; if (good) right++; sel.closest('.dm-mtrow').classList.add(good ? 'ok' : 'bad');
            if (!good) sel.closest('.dm-mtrow').insertAdjacentHTML('beforeend', '<small>' + ABC[q.opts.findIndex(function (o) { return norm(o) === norm(p.answer); })] + ': ' + esc(clip(p.answer, 120)) + '</small>');
          });
          got += right; total += q.pairs.length; ok = right === q.pairs.length;
          fb.innerHTML = '<b>' + right + ' of ' + q.pairs.length + ' matched</b>';
        } else {
          total++;
          if (v === null || v === '') blank++;
          if (q.type === 'mc') ok = v !== null && norm(q.opts[+v]) === norm(q.answer);
          if (q.type === 'tf') ok = v !== null && (v === '1') === q.truth;
          if (q.type === 'wr') ok = same(v, q.answer);
          if (ok) got++;
          fb.innerHTML = ok ? '<b>Correct</b>' : '<b>' + (v === null || v === '' ? 'No answer.' : 'Incorrect.') + '</b> ' + (q.type === 'tf' ? 'It is ' + (q.truth ? 'true' : 'false') + '. ' : '') + '<span class="dm-right">Answer: ' + esc(q.answer) + '</span>'
            + (q.type === 'wr' && v ? '<span class="dm-you">You wrote: ' + esc(v) + '</span>' : '');
          if (q.type === 'mc') li.querySelectorAll('.dm-radio').forEach(function (l, k) { if (norm(q.opts[k]) === norm(q.answer)) l.classList.add('right'); else if (String(k) === v) l.classList.add('wrong'); });
        }
        li.classList.add(ok ? 'ok' : 'bad'); fb.hidden = false;
      });
      return { got: got, total: total, blank: blank };
    }
    $('tsSetup').addEventListener('submit', function (e) {
      e.preventDefault(); var f = {}; new FormData(this).forEach(function (v, k) { f[k] = v; }); var err = this.querySelector('.err'); err.textContent = '';
      if (!f.mc && !f.tf && !f.wr && !f.mt) { err.textContent = 'Pick at least one question type.'; return; }
      qs = build(f); render(); this.hidden = true; $('tsResult').hidden = true; $('tsPaper').hidden = false;
      $('tsPaper').querySelectorAll('input,select,button').forEach(function (x) { x.disabled = false; });
      t0 = Date.now(); clearInterval(clock); $('tsClock').hidden = !f.timer;
      if (f.timer) clock = setInterval(function () { var s = Math.round((Date.now() - t0) / 1000); $('tsClock').textContent = Math.floor(s / 60) + ':' + ('0' + s % 60).slice(-2); }, 1000);
      var first = $('tsQs').querySelector('input,select'); if (first) first.focus({ preventScroll: true }); window.scrollTo({ top: $('tsPaper').getBoundingClientRect().top + scrollY - 90 });
    });
    $('tsPaper').addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(this), empty = qs.filter(function (q, n) { return q.type === 'mt' ? q.pairs.some(function (p, k) { return !fd.get('q' + n + '_' + k); }) : !fd.get('q' + n); }).length;
      if (empty && !confirm(empty + ' question' + (empty === 1 ? ' is' : 's are') + ' not answered. Submit anyway?')) return;
      clearInterval(clock); var secs = Math.round((Date.now() - t0) / 1000), r = grade(), pct = Math.round(100 * r.got / Math.max(1, r.total));
      this.querySelectorAll('input,select,button').forEach(function (x) { x.disabled = true; });
      $('tsPct').textContent = pct + '%'; $('tsRing').setAttribute('stroke-dasharray', pct + ' 100');
      $('tsMsg').textContent = pct >= 90 ? 'Excellent. You are exam ready on this set.' : pct >= 70 ? 'Good work. A little more practice and you have it.' : pct >= 50 ? 'Halfway there. Learn the ones you missed.' : 'Keep going. Learn mode will help these stick.';
      $('tsSub').textContent = r.got + ' of ' + r.total + ' correct in ' + Math.floor(secs / 60) + ' min ' + secs % 60 + ' s. Your answers are marked below.';
      $('tsResult').hidden = false; $('tsResult').scrollIntoView({ behavior: 'smooth', block: 'start' });
      save(deck, 'test', r.total, r.got, secs).then(function (s) { if (s.best !== undefined) $('tsSub').textContent += ' Your best on this set: ' + s.best + '%.'; });
    });
    $('tsRetake').addEventListener('click', function () { $('tsPaper').hidden = true; $('tsResult').hidden = true; $('tsSetup').hidden = false; $('tsSetup').scrollIntoView({ behavior: 'smooth' }); });
    load(deck).then(function (c) {
      items = c; var max = Math.min(50, items.length); $('tsN').max = max; if (+$('tsN').value > max) $('tsN').value = max;
      if (items.length < 2) { $('tsSetup').querySelector('.err').textContent = 'This set needs at least 2 cards with answers to make a test.'; $('tsSetup').querySelector('[type=submit]').disabled = true; }
    }).catch(function (e) { $('tsSetup').querySelector('.err').textContent = e.message; });
  })();
})();
