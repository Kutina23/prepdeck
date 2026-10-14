// Notes app. The editor lives inside a modal sheet: "New note" or clicking a note opens it.
(function () {
  var app = document.getElementById('notesapp'); if (!app) return;
  var f = null, err = null;
  function openEditor(b) {
    var t = document.getElementById('tpl-note-editor');
    if (!t || typeof window.openSheet !== 'function') return;
    window.openSheet(b ? 'Edit note' : 'New note', t.innerHTML);
    f = document.getElementById('neditor'); if (!f) return;
    err = f.querySelector('.err');
    f.id.value = b ? b.dataset.id : 0;
    f.title.value = b ? b.dataset.title : '';
    f.body.value = b ? b.dataset.body : '';
    if (f.course_id) f.course_id.value = b ? b.dataset.course : '';
    var del = document.getElementById('ndel'); if (del) del.hidden = !b;
    if (err) { err.textContent = ''; err.style.color = ''; }
    document.querySelectorAll('.nitem').forEach(function (n) { n.style.borderColor = b && n === b ? 'var(--ink)' : ''; });
    if (f.title) f.title.focus();
  }
  app.addEventListener('click', function (e) { var b = e.target.closest('.nitem'); if (b) openEditor(b); });
  var nn = document.getElementById('newnote');
  if (nn) nn.onclick = function () {
    if (nn.dataset.full) { if (window.toast) toast('Free accounts keep ' + app.dataset.limit + ' note. Upgrade to Plus for unlimited notes.'); return; }
    openEditor(null);
  };
  document.addEventListener('submit', function (e) {
    if (e.target.id !== 'neditor') return;
    e.preventDefault();
    api('notes.php?a=save', { id: +f.id.value, title: f.title.value, body: f.body.value, course_id: f.course_id ? f.course_id.value : 0 })
      .then(function () { location.reload(); })
      .catch(function (x) { if (err) { err.textContent = x.message; err.style.color = 'var(--bad)'; } else if (window.toast) toast(x.message); });
  });
  document.addEventListener('click', function (e) {
    if (e.target.id !== 'ndel') return;
    var doDelete = function () {
      api('notes.php?a=delete', { id: +f.id.value })
        .then(function () { location.reload(); })
        .catch(function (x) { if (err) { err.textContent = x.message; err.style.color = 'var(--bad)'; } else if (window.toast) toast(x.message); });
    };
    if (window.confirmDialog) window.confirmDialog('Delete this note?', { ok: 'Delete', title: 'Delete note' }).then(function (ok) { if (ok) doDelete(); });
    else if (window.confirm('Delete this note?')) doDelete();
  });
})();
