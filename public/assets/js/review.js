// Smart review session: show a card, reveal the answer, rate it (Again / Hard / Good / Easy). The server schedules the next review.
(function () {
  var box = document.getElementById('review'); if (!box) return;
  var $ = function (id) { return document.getElementById(id); };
  var card = $('rvcard'), q = $('rvq'), ans = $('rva'), tag = $('rvtag'), rate = $('rvrate'), hint = $('rvhint');
  var queue = [], cur = null, shown = false, busy = false, total = 0, done = 0, good = 0, goal = 20, today = 0;

  function progress() {
    $('rvcount').textContent = queue.length + (cur ? 1 : 0) + ' left · ' + today + ' / ' + goal + ' today';
    $('rvbar').style.width = (total ? Math.round(100 * done / (done + queue.length + (cur ? 1 : 0))) : 0) + '%';
  }
  function next() {
    cur = queue.shift() || null; shown = false; rate.hidden = true; ans.hidden = true; hint.hidden = false;
    if (!cur) return finish();
    q.textContent = cur.front; ans.textContent = cur.back === null ? 'The answer on this card is part of Plus.' : cur.back;
    tag.hidden = !cur.new; $('rvdeck').textContent = cur.deck;
    rate.querySelectorAll('button').forEach(function (b) { b.querySelector('small').textContent = cur.next[b.dataset.r] || ''; });
    card.disabled = false; card.classList.remove('flip'); void card.offsetWidth; card.classList.add('enter');
    progress();
  }
  function reveal() {
    if (!cur || shown) return; shown = true; ans.hidden = false; hint.hidden = true; rate.hidden = false; card.classList.add('flip');
    var g = rate.querySelector('[data-r="3"]'); if (g) g.focus();
  }
  function grade(r) {
    if (!cur || !shown || busy) return; busy = true; var c = cur;
    api('study.php?a=rate', { card_id: c.id, rating: r }).then(function (res) {
      done++; today = res.done_today; if (r >= 3) good++;
      if (res.again) { c.new = false; c.next = res.labels; queue.splice(Math.min(3, queue.length), 0, c); }   // Again: see it again in a few cards
      busy = false; next();
      if (today === goal && window.toast) toast('Daily goal reached. Well done!');
    }).catch(function (e) { busy = false; if (window.toast) toast(e.message); });
  }
  function finish() {
    card.hidden = true; rate.hidden = true; $('rvdone').hidden = false; $('rvbar').style.width = '100%';
    $('rvcount').textContent = today + ' / ' + goal + ' today';
    $('rvdt').textContent = total ? 'Session complete' : 'Nothing to review right now';
    $('rvds').textContent = total ? 'You reviewed ' + done + ' card' + (done === 1 ? '' : 's') + ' and knew ' + Math.round(100 * good / Math.max(1, done)) + '% of them. The next ones are scheduled just before you would forget them.'
      : 'No cards are due and there are no new cards left for today. Come back tomorrow, or pick a deck from the Study hub to add new cards.';
  }

  card.addEventListener('click', reveal);
  rate.addEventListener('click', function (e) { var b = e.target.closest('button[data-r]'); if (b) grade(+b.dataset.r); });
  document.addEventListener('keydown', function (e) {
    if (e.target.matches('input,textarea,select') || document.body.classList.contains('modal-open')) return;
    if ((e.key === ' ' || e.key === 'Enter') && !shown && cur) { e.preventDefault(); reveal(); }
    else if (/^[1-4]$/.test(e.key) && shown) { e.preventDefault(); grade(+e.key); }
  });

  api('study.php?a=queue' + (box.dataset.deck !== '0' ? '&deck=' + box.dataset.deck : '')).then(function (r) {
    queue = r.cards; total = queue.length; goal = r.goal; today = r.done_today; next();
  }).catch(function (e) { $('rvdeck').textContent = ''; q.textContent = e.message; });
})();
