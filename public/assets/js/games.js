// Match (flip pairs) and Scatter (drag-free tap matching against the clock).
(function () {
  var box = document.getElementById('game'); if (!box) return;
  var board = document.getElementById('gboard'), scoreEl = document.getElementById('gscore'), timeEl = document.getElementById('gtime'), msg = document.getElementById('gmsg');
  var t0 = Date.now(), timer, score = 0, done = false;
  function fmt(s) { return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); }
  function shuffle(a) { for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)), t = a[i]; a[i] = a[j]; a[j] = t; } return a; }
  function setScore(n) { score = n; scoreEl.textContent = 'Score ' + n; }
  function finish(pairs) {
    done = true; clearInterval(timer); var secs = Math.round((Date.now() - t0) / 1000), bonus = Math.max(0, pairs * 12 - secs), final = score + bonus; setScore(final);
    msg.textContent = 'Done in ' + fmt(secs) + '. Score ' + final + '.'; api('games.php?a=score', { game: box.dataset.game, deck: +box.dataset.deck, score: final, seconds: secs }).catch(function () {});
    board.insertAdjacentHTML('beforeend', '<div class="inline" style="margin-top:12px"><button class="btn" onclick="location.reload()">Play again</button></div>');
  }
  api('games.php?a=pairs&deck=' + box.dataset.deck + '&game=' + box.dataset.game).then(function (r) {
    var pairs = r.pairs.slice(0, 8); if (pairs.length < 3) { board.textContent = 'This deck needs at least 3 cards.'; return; }
    timer = setInterval(function () { timeEl.textContent = fmt(Math.round((Date.now() - t0) / 1000)); }, 500);
    var tiles = [], n = 0;
    pairs.forEach(function (p, i) { tiles.push({ k: i, t: p.front, side: 'q' }); tiles.push({ k: i, t: p.back || '(locked)', side: 'a' }); });
    shuffle(tiles); board.innerHTML = ''; board.className = 'gboard';
    var first = null, left = pairs.length;
    tiles.forEach(function (tl) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'gtile ' + tl.side; b.textContent = tl.t; board.appendChild(b);
      b.addEventListener('click', function () {
        if (done || b.disabled) return;
        if (!first) { first = { b: b, tl: tl }; b.setAttribute('aria-pressed', 'true'); return; }
        if (first.b === b) { b.setAttribute('aria-pressed', 'false'); first = null; return; }
        var a = first; first = null;
        if (a.tl.k === tl.k && a.tl.side !== tl.side) { [a.b, b].forEach(function (x) { x.disabled = true; x.classList.add('ok'); x.setAttribute('aria-pressed', 'false'); }); setScore(score + 20); if (--left === 0) finish(pairs.length); }
        else { setScore(Math.max(0, score - 5)); [a.b, b].forEach(function (x) { x.classList.add('no'); setTimeout(function () { x.classList.remove('no'); x.setAttribute('aria-pressed', 'false'); }, 450); }); }
      });
    });
  }).catch(function (e) { board.textContent = e.message; });
})();
