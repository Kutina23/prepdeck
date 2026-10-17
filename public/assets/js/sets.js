// Lecturer flashcard set editor: add / remove cards, Tab from the last definition adds a card, paste-import, swap sides.
(function () {
  var form = document.getElementById('setForm'); if (!form) return;
  var list = document.getElementById('setCards');
  function grow(t) { t.style.height = 'auto'; t.style.height = Math.min(240, t.scrollHeight) + 'px'; }
  function renumber() {
    var n = 0; list.querySelectorAll('.setcard').forEach(function (li, i) { li.querySelector('.setn').textContent = i + 1; if (li.querySelector('[name="front[]"]').value.trim() || li.querySelector('[name="back[]"]').value.trim()) n++; });
    document.getElementById('setCount').textContent = n + ' card' + (n === 1 ? '' : 's');
  }
  function row(front, back) {
    var tpl = list.querySelector('.setcard'), li = tpl.cloneNode(true);
    li.querySelector('[name="cid[]"]').value = '0';
    li.querySelector('[name="front[]"]').value = front || ''; li.querySelector('[name="back[]"]').value = back || '';
    list.appendChild(li); li.querySelectorAll('textarea').forEach(grow); renumber(); return li;
  }
  list.querySelectorAll('textarea').forEach(grow);
  list.addEventListener('input', function (e) { if (e.target.tagName === 'TEXTAREA') { grow(e.target); renumber(); } });
  list.addEventListener('keydown', function (e) {
    if (e.key === 'Tab' && !e.shiftKey && e.target.name === 'back[]' && e.target.closest('.setcard') === list.lastElementChild) { e.preventDefault(); row().querySelector('textarea').focus(); }
  });
  list.addEventListener('click', function (e) {
    var b = e.target.closest('.setdel'); if (!b) return;
    var li = b.closest('.setcard');
    if (list.children.length > 2) li.remove(); else li.querySelectorAll('textarea').forEach(function (t) { t.value = ''; });
    renumber();
  });
  document.getElementById('setAdd').addEventListener('click', function () { row().querySelector('textarea').focus(); });
  document.getElementById('setSwap').addEventListener('click', function () {
    list.querySelectorAll('.setcard').forEach(function (li) { var f = li.querySelector('[name="front[]"]'), b = li.querySelector('[name="back[]"]'), x = f.value; f.value = b.value; b.value = x; grow(f); grow(b); });
  });
  form.addEventListener('submit', function () { form.querySelector('[type=submit]').disabled = true; });

  // import: opens in the sheet (cloned from the template), so listen on the document
  var SEP = { tab: /\t/, dash: /\s+[-–—]\s+/, colon: /\s*:\s*/, comma: /\s*,\s*/ };
  function parse() {
    var txt = document.getElementById('imt').value, sep = SEP[document.getElementById('ims').value];
    return txt.split(/\r?\n/).map(function (l) { var i = l.search(sep); if (i < 0) return null; var m = l.match(sep)[0]; return [l.slice(0, i).trim(), l.slice(i + m.length).trim()]; })
      .filter(function (p) { return p && p[0] && p[1]; });
  }
  document.addEventListener('input', function (e) { if (e.target.id === 'imt') document.getElementById('imPrev').textContent = parse().length + ' card(s) found.'; });
  document.addEventListener('change', function (e) { if (e.target.id === 'ims') document.getElementById('imPrev').textContent = parse().length + ' card(s) found.'; });
  document.addEventListener('submit', function (e) {
    if (e.target.id !== 'setImport') return; e.preventDefault();
    var cards = parse(); if (!cards.length) { document.getElementById('imPrev').textContent = 'No cards found. Check the separator.'; return; }
    if (document.getElementById('imm').value === 'replace') { var keep = list.querySelector('.setcard'); list.querySelectorAll('.setcard').forEach(function (li, i) { if (i) li.remove(); }); keep.querySelectorAll('textarea').forEach(function (t) { t.value = ''; }); keep.querySelector('[name="cid[]"]').value = '0'; }
    // fill empty rows first, then add
    var empty = Array.prototype.filter.call(list.querySelectorAll('.setcard'), function (li) { return !li.querySelector('[name="front[]"]').value.trim() && !li.querySelector('[name="back[]"]').value.trim(); });
    cards.forEach(function (c) { var li = empty.shift(); if (li) { li.querySelector('[name="front[]"]').value = c[0]; li.querySelector('[name="back[]"]').value = c[1]; li.querySelectorAll('textarea').forEach(grow); } else row(c[0], c[1]); });
    renumber(); if (window.closeSheet) closeSheet(); if (window.toast) toast(cards.length + ' card' + (cards.length === 1 ? '' : 's') + ' imported. Check them, then save.');
  });
  renumber();
})();
