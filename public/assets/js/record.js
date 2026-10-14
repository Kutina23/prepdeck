// In-browser lecture recorder (MediaRecorder). The recorded blob is attached to the form as a file.
// The recorder form lives inside a modal sheet, so it is initialised when the sheet opens.
(function () {
  function init() {
    var btn = document.getElementById('recbtn'); if (!btn || btn.dataset.ready) return; btn.dataset.ready = '1';
    var form = document.getElementById('recform'), time = document.getElementById('rectime'), play = document.getElementById('recplay'), note = document.getElementById('recnote');
    var rec, chunks = [], t0, tick, stream;
    function fmt(s) { return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); }
    if (!navigator.mediaDevices || !window.MediaRecorder) { btn.disabled = true; note.textContent = 'This browser cannot record. Upload an audio file instead.'; return; }
    btn.addEventListener('click', async function () {
      if (rec && rec.state === 'recording') { rec.stop(); return; }
      try { stream = await navigator.mediaDevices.getUserMedia({ audio: true }); } catch (e) { note.textContent = 'Microphone blocked. Allow it in your browser, or upload a file.'; return; }
      chunks = []; rec = new MediaRecorder(stream);
      rec.ondataavailable = function (e) { if (e.data.size) chunks.push(e.data); };
      rec.onstop = function () {
        clearInterval(tick); stream.getTracks().forEach(function (t) { t.stop(); });
        var type = rec.mimeType || 'audio/webm', ext = /mp4/.test(type) ? 'm4a' : (/ogg/.test(type) ? 'ogg' : 'webm'), blob = new Blob(chunks, { type: type });
        var file = new File([blob], 'lecture.' + ext, { type: type }), dt = new DataTransfer(); dt.items.add(file); document.getElementById('rf').files = dt.files;
        play.src = URL.createObjectURL(blob); play.hidden = false; btn.querySelector('span').textContent = 'Record again'; note.textContent = 'Recording ready. Press Upload and transcribe.';
      };
      rec.start(1000); t0 = Date.now(); btn.querySelector('span').textContent = 'Stop recording'; note.textContent = 'Recording… keep this tab open.';
      tick = setInterval(function () { time.textContent = fmt(Math.round((Date.now() - t0) / 1000)); }, 500);
    });
  }
  document.addEventListener('sheetopen', function (e) { if (e.detail && e.detail.sheet === 'tpl-rec') init(); });
  init();
})();
