// Flashcard study mode + deck builder.
(function () {
  var $ = function (s) { return document.querySelector(s); };
  var box = $('#study');
  if (box) {
    var cards = [], i = 0, known = {}, canTrack = false, locked = false;
    var fc = $('#fc'), fq = $('#fq'), fa = $('#fa');
    var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function show() {
      if (!cards.length) { fq.textContent = 'This deck has no cards yet.'; fa.textContent = ''; return; }
      var c = cards[i]; fc.setAttribute('aria-pressed', 'false'); fq.textContent = c.front;
      fc.classList.remove('enter'); void fc.offsetWidth; fc.classList.add('enter');   // replay the entrance for each new card
      fa.textContent = c.back === null ? 'Solved answers are part of Plus.' : c.back;
      $('#sct').textContent = (i + 1) + ' / ' + cards.length + (canTrack ? ' · ' + Object.keys(known).filter(function (k) { return known[k]; }).length + ' known' : '');
      $('#sbar').style.width = Math.round(100 * (i + 1) / cards.length) + '%';
    }
    function go(d) { if (!cards.length) return; i = (i + d + cards.length) % cards.length; show(); }
    function mark(k) { var c = cards[i]; if (!c) return; known[c.id] = k; if (canTrack) api('decks.php?a=progress', { card_id: c.id, known: k }).catch(function () {}); else if (window.toast && k) toast('Plus saves what you know.'); go(1); }
    // throw the card off to the right (know it) or left (still learning), then mark it and show the next one
    var busy = false;
    function fling(k) {
      if (busy || !cards.length) return; busy = true;
      fc.dataset.lean = k ? 'r' : 'l';
      fc.style.transform = 'translateX(' + (k ? 120 : -120) + '%) rotate(' + (k ? 14 : -14) + 'deg)'; fc.style.opacity = '0';
      setTimeout(function () {
        fc.style.transition = 'none'; fc.style.transform = ''; fc.style.opacity = ''; delete fc.dataset.lean;
        mark(k); void fc.offsetWidth; fc.style.transition = ''; busy = false;
      }, still ? 0 : 260);
    }
    // touch: drag the card sideways; past 80px it counts as a swipe, otherwise it springs back
    var sx = null, sy = 0, dx = 0, dragging = false, swallow = false;
    fc.addEventListener('touchstart', function (e) { if (e.touches.length !== 1 || busy) return; sx = e.touches[0].clientX; sy = e.touches[0].clientY; dx = 0; dragging = false; }, { passive: true });
    fc.addEventListener('touchmove', function (e) {
      if (sx === null) return; dx = e.touches[0].clientX - sx; var dy = e.touches[0].clientY - sy;
      if (!dragging) { if (Math.abs(dx) < 10) return; if (Math.abs(dy) > Math.abs(dx)) { sx = null; return; } dragging = true; fc.style.transition = 'none'; }
      fc.style.transform = 'translateX(' + dx + 'px) rotate(' + (dx / 24) + 'deg)';
      if (dx > 50) fc.dataset.lean = 'r'; else if (dx < -50) fc.dataset.lean = 'l'; else delete fc.dataset.lean;
    }, { passive: true });
    fc.addEventListener('touchend', function () {
      if (sx === null) return; sx = null; if (!dragging) return;
      swallow = true; setTimeout(function () { swallow = false; }, 400);   // the tap that ends a drag must not flip the card
      fc.style.transition = '';
      if (Math.abs(dx) > 80) fling(dx > 0 ? 1 : 0); else { fc.style.transform = ''; delete fc.dataset.lean; }
    });
    fc.addEventListener('click', function () { if (swallow) return; fc.setAttribute('aria-pressed', fc.getAttribute('aria-pressed') === 'true' ? 'false' : 'true'); });
    $('#sprev').onclick = function () { go(-1); }; $('#snext').onclick = function () { go(1); };
    $('#sgood').onclick = function () { fling(1); }; $('#sbad').onclick = function () { fling(0); };
    $('#sshuf').onclick = function () { for (var n = cards.length - 1; n > 0; n--) { var j = Math.floor(Math.random() * (n + 1)); var t = cards[n]; cards[n] = cards[j]; cards[j] = t; } i = 0; show(); };
    document.addEventListener('keydown', function (e) { if (/INPUT|TEXTAREA|SELECT/.test(e.target.tagName)) return; if (e.key === 'ArrowRight') go(1); else if (e.key === 'ArrowLeft') go(-1); else if (e.key === ' ') { e.preventDefault(); fc.click(); } });
    api('decks.php?a=cards&id=' + box.dataset.deck).then(function (r) {
      cards = r.cards; locked = r.locked; canTrack = r.can_track; (r.known || []).forEach(function (k) { known[k] = 1; });
      var first = cards.findIndex(function (c) { return !known[c.id]; }); i = first > 0 ? first : 0; show();
    }).catch(function (e) { fq.textContent = e.message; });
  }
  function initDeckBuilder() {
    var rows = $('#cardrows'); if (!rows || rows.dataset.ready) return; rows.dataset.ready = '1';
    var n = 0;
    function add() { n++; var d = document.createElement('div'); d.className = 'card stack'; d.style.padding = '12px';
      d.innerHTML = '<div class="field"><label>Front ' + n + '</label><textarea class="in" name="front[]" rows="2" style="padding-top:12px" maxlength="2000"></textarea></div><div class="field"><label>Back ' + n + '</label><textarea class="in" name="back[]" rows="2" style="padding-top:12px" maxlength="2000"></textarea></div>'; rows.appendChild(d); }
    for (var k = 0; k < 3; k++) add();
    var ac = $('#addcard'); if (ac) ac.onclick = add;
    var df = $('#deckform');
    if (df) df.addEventListener('submit', async function (e) {
      e.preventDefault(); e.stopImmediatePropagation(); var f = e.target, err = f.querySelector('.err'); err.textContent = '';
      var fr = f.querySelectorAll('[name="front[]"]'), bk = f.querySelectorAll('[name="back[]"]'), cards = [];
      fr.forEach(function (x, j) { if (x.value.trim() && bk[j].value.trim()) cards.push({ front: x.value, back: bk[j].value }); });
      try { var r = await api('decks.php?a=create', { title: f.title.value, course_id: f.course_id.value, cards: cards }); location.href = PDBASE + 'dashboard/decks.php?id=' + r.id; } catch (x) { err.textContent = x.message; err.style.color = 'var(--bad)'; }
    }, true);
  }
  initDeckBuilder();
  document.addEventListener('sheetopen', function (e) { if (e.detail && e.detail.sheet === 'tpl-newdeck') initDeckBuilder(); });
})();
