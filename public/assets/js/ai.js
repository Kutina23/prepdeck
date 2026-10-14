(function () {
  var box = document.getElementById('aichat'); if (!box) return;
  var msgs = document.getElementById('msgs'), form = document.getElementById('askform'), inp = document.getElementById('askin'), hist = [], busy = false;
  function md(t) { var h = esc(t).replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>'); var out = '', list = false;
    h.split('\n').forEach(function (l) { if (/^\s*[-*] /.test(l)) { if (!list) { out += '<ul>'; list = true; } out += '<li>' + l.replace(/^\s*[-*] /, '') + '</li>'; } else { if (list) { out += '</ul>'; list = false; } if (l.trim()) out += '<p>' + l + '</p>'; } });
    return out + (list ? '</ul>' : ''); }
  function add(cls, html) { var d = document.createElement('div'); d.className = 'msg ' + cls; d.innerHTML = html; msgs.appendChild(d); msgs.scrollTop = msgs.scrollHeight; return d; }
  async function send(text) {
    if (busy || !text.trim()) return; busy = true; add('me', esc(text)); hist.push({ role: 'user', content: text });
    var w = add('bot', '<span class="dots"><i></i><i></i><i></i></span>');
    try { var r = await api('ai.php', { messages: hist, course_id: document.getElementById('aicourse').value, mode: document.getElementById('aimode').value });
      w.innerHTML = md(r.text); hist.push({ role: 'assistant', content: r.text }); document.getElementById('aileft').textContent = r.left + ' left today'; }
    catch (e) { w.className = 'msg bot err'; w.textContent = e.message; hist.pop(); }
    busy = false; msgs.scrollTop = msgs.scrollHeight;
  }
  form.addEventListener('submit', function (e) { e.preventDefault(); var t = inp.value; inp.value = ''; send(t); });
  box.addEventListener('click', function (e) { var b = e.target.closest('[data-ask]'); if (b) { inp.value = b.dataset.ask; inp.focus(); } });
  if (box.dataset.q) { inp.value = box.dataset.q; inp.focus(); }
})();
