// Settings > Two-step sign-in: draw the set-up QR code inside its sheet template, and copy / download fresh recovery codes.
(function () {
  // the sheet is built from the template's HTML, so the QR code is drawn into the template once, before it is opened
  var tpl = document.getElementById('tpl-twofa-on'), box = tpl && tpl.content.querySelector('[data-qr]');
  if (box && window.qrcode) {
    var q = qrcode(0, 'M'); q.addData(box.dataset.qr); q.make();
    box.innerHTML = q.createSvgTag({ cellSize: 4, margin: 16, scalable: true });
  }

  var list = document.getElementById('rcodes'); if (!list) return;
  var text = function () { return 'PrepDeck recovery codes (each works once)\n\n' + Array.prototype.map.call(list.querySelectorAll('code'), function (c) { return c.textContent; }).join('\n') + '\n'; };
  document.getElementById('rcCopy').addEventListener('click', function () {
    if (navigator.clipboard) navigator.clipboard.writeText(text()).then(function () { toast('Recovery codes copied.'); }, function () { toast('Copy failed. Select the codes and copy them.'); });
  });
  document.getElementById('rcSave').addEventListener('click', function () {
    var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([text()], { type: 'text/plain' })); a.download = 'prepdeck-recovery-codes.txt';
    document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 0);
  });
})();
