// Generic AJAX forms:
//   <form data-api="notes.php?a=save" [data-done="message"] [data-redirect="url"] [data-job]>
//   <form data-action="admin/announcements.php" method="post">  (posts to the page handler, expects JSON)
// Buttons:  <button data-api="decks.php?a=delete" data-body='{"id":3}' data-confirm="Delete?">
(function () {
  function say(f, m, bad) { var e = f.querySelector('.err'); if (e) { e.textContent = m || ''; e.style.color = bad ? 'var(--bad)' : ''; } else if (m && window.toast) toast(m); }
  function finishSheet(r) {
    if (r && r.flash && window.toast) r.flash.forEach(function (i) { toast(i[1]); });
    var m = document.getElementById('modal');
    if (m && !m.hidden && typeof window.closeSheet === 'function') window.closeSheet();
  }
  document.addEventListener('submit', async function (ev) {
    var f = ev.target.closest('form[data-api], form[data-action]'); if (!f || f.hasAttribute('data-custom')) return; ev.preventDefault();
    var btn = ev.submitter || f.querySelector('[type=submit], button:not([type])');
    var fd = new FormData(f); if (ev.submitter && ev.submitter.name) fd.append(ev.submitter.name, ev.submitter.value); // FormData(f) leaves out the clicked button
    if (btn) btn.disabled = true; say(f, '');
    try {
      if (f.dataset.action) {
        var ctrl = new AbortController(), fto = setTimeout(function () { ctrl.abort(); }, 60000);
        var res;
        try {
          res = await fetch(PDBASE + f.dataset.action, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf() }, body: fd, signal: ctrl.signal });
        } catch (e) {
          clearTimeout(fto);
          throw Object.assign(new Error(e.name === 'AbortError' ? 'Request timed out. Please try again.' : 'Network error. Please check your connection.'), { status: 0 });
        }
        clearTimeout(fto);
        var r = await res.json().catch(function () { return {}; });
        if (!r.ok) throw Object.assign(new Error(r.error || 'Something went wrong.'), { status: res.status });
        finishSheet(r);
        // the server sends url(...), which already starts with the base path; only bare relative paths get it added
        if (r.redirect) location.href = /^(https?:)?\/\//.test(r.redirect) || r.redirect[0] === '/' ? r.redirect : PDBASE + r.redirect;
        else if (r.reload !== undefined && !r.reload) return;
        else location.reload();
        return;
      }
      var hasFile = f.querySelector('input[type=file]'), data;
      if (hasFile) data = fd; else { data = {}; fd.forEach(function (v, k) { if (k.slice(-2) === '[]') { (data[k.slice(0, -2)] = data[k.slice(0, -2)] || []).push(v); } else data[k] = v; }); }
      var r = await api(f.dataset.api, data);
      if (f.hasAttribute('data-job') && r.job_id && window.watchJob) { window.watchJob(r.job_id, f, function (status) { if (status === 'done') location.reload(); else if (btn) btn.disabled = false; }); return; }
      if (f.dataset.done) { say(f, f.dataset.done); f.reset && f.reset(); }
      if (f.dataset.redirect) { var to = f.dataset.redirect.replace('{id}', r.id || r.material_id || r.recording_id || ''); location.href = to.indexOf('http') === 0 || to[0] === '/' ? to : PDBASE + to; }
      else if (!f.dataset.done) location.reload();
    } catch (e) { say(f, e.message, true); if (e.status === 402 && window.toast) toast(e.message); }
    if (btn) btn.disabled = false;
  });
  document.addEventListener('click', async function (ev) {
    var b = ev.target.closest('button[data-api]'); if (!b) return; ev.preventDefault();
    b.disabled = true;
    try { var r = await api(b.dataset.api, b.dataset.body ? JSON.parse(b.dataset.body) : {}); if (b.hasAttribute('data-stay')) { if (window.toast) toast(r.message || 'Done.'); b.disabled = false; return; } if (b.dataset.redirect) location.href = PDBASE + b.dataset.redirect; else location.reload(); }
    catch (e) { if (window.toast) toast(e.message); b.disabled = false; }
  });
})();
