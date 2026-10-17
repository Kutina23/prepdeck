// PrepDeck Community: join / unlock with the account password, then a two-pane messenger (chats, requests, find people).
// New messages arrive by polling (every 3 s in an open chat while the tab is visible, 15 s for the lists).
(function () {
  var $ = function (s, r) { return (r || document).querySelector(s); };
  function gate(id, action) {
    var f = document.getElementById(id); if (!f) return;
    f.addEventListener('submit', function (e) {
      e.preventDefault(); var err = $('.err', f), b = $('button[type=submit]', f); err.textContent = ''; b.disabled = true;
      api('community.php?a=' + action, Object.fromEntries(new FormData(f))).then(function (r) { return keysAfterPassword(f, r); })
        .catch(function (x) { err.textContent = x.message; b.disabled = false; var p = $('[type=password]', f); if (p) { p.value = ''; p.focus(); } });
    });
  }
  gate('cmJoin', 'join'); gate('cmUnlock', 'unlock');

  /* ---------- end-to-end encryption keys (see e2ee.js) ---------- */
  // The password was just accepted by the server: use it here, in the browser, to unlock (or create) this member's keys.
  function keysAfterPassword(f, r) {
    var pw = $('[type=password]', f).value, uid = +f.dataset.uid;
    if (!window.E2EE || !E2EE.supported || !r.esid) return location.reload();
    return E2EE.setup(uid, pw, r.esid).then(function (st) {
      if (st !== 'stale') return location.reload();
      var box = document.createElement('div'); f.replaceWith(box); staleKeys(box, uid, pw, r.esid, function () { location.reload(); });
    }).catch(function () { location.reload(); });   // files can still be unlocked later from the chat screen
  }
  /** The password changed since the keys were made: re-wrap them with the old password, or start new keys. */
  function staleKeys(box, uid, pw, esid, done) {
    box.className = 'cm-stale';
    box.innerHTML = '<p><b>Your password changed</b> since your file encryption keys were made. Enter your previous PrepDeck password to keep opening files you were sent before.</p>'
      + '<div class="field"><label for="cmOld">Previous password</label><input class="in" id="cmOld" type="password" autocomplete="off"></div><p class="err" role="alert"></p>'
      + '<div class="rowact"><button type="button" class="btn" data-keep>Keep my files</button><button type="button" class="btn line" data-fresh>Start new keys</button></div>'
      + '<p class="note">New keys work for everything from now on, but files sent to you before cannot be opened.</p>';
    var err = $('.err', box);
    $('[data-keep]', box).addEventListener('click', function () {
      var b = this; b.disabled = true; err.textContent = 'Checking…';
      E2EE.rewrap(uid, $('#cmOld').value, pw, esid).then(function (ok) { if (ok) done(); else { err.textContent = 'That is not your previous password.'; b.disabled = false; } })
        .catch(function (x) { err.textContent = x.message; b.disabled = false; });
    });
    $('[data-fresh]', box).addEventListener('click', function () {
      if (!confirm('Start new encryption keys? Files sent to you before cannot be opened afterwards.')) return;
      this.disabled = true; E2EE.fresh(uid, pw, esid).then(done).catch(function (x) { err.textContent = x.message; });
    });
    $('#cmOld').focus();
  }
  if (!document.getElementById('cm') && window.E2EE && E2EE.supported) E2EE.forget();   // locked or not joined: no keys stay in this browser

  var cm = document.getElementById('cm'); if (!cm) return;
  var me = +cm.dataset.me, list = $('#cmList'), tab = 'chats', open = null, lastId = 0, firstId = 0, timer = null, listTimer = null, threads = [];
  var main = $('#cmMain'), thread = $('#cmThread'), msgs = $('#cmMsgs'), person = $('#cmPerson'), empty = $('#cmEmpty');
  var esid = cm.dataset.esid, myKid = false, keyCache = {}, files = {}, maxFile = +cm.dataset.max;
  function keep(r) { if (r && r.keys) Object.keys(r.keys).forEach(function (k) { keyCache[k] = r.keys[k]; }); return r; }

  function call(path, data) {
    // 423 = locked again (idle for an hour): reload to show the password screen
    return api('community.php?a=' + path, data).catch(function (e) { if (e.status === 423) location.reload(); throw e; });
  }
  function av(p) { return '<span class="cm-av" style="--h:' + p.hue + '">' + esc(p.initials) + (p.online ? '<i class="cm-on" title="Online"></i>' : '') + '</span>'; }
  function sub(p) { return [p.school, p.role === 'Student' ? p.level : p.role].filter(Boolean).join(' · '); }
  function badge(el, n) { el.hidden = !n; el.textContent = n > 99 ? '99+' : n; }
  function rel(p) {
    if (p.relation === 'friends') return '<button class="btn sm" data-chat="' + p.id + '">Message</button>';
    if (p.relation === 'sent') return '<button class="btn sm line" data-do="cancel" data-id="' + p.id + '">Requested · Cancel</button>';
    if (p.relation === 'received') return '<button class="btn sm" data-do="respond" data-accept="1" data-id="' + p.id + '">Accept</button><button class="btn sm line" data-do="respond" data-id="' + p.id + '">Decline</button>';
    return '<button class="btn sm" data-do="request" data-id="' + p.id + '">' + '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Add friend</button>';
  }

  /* ---------- lists ---------- */
  function showTab(t) {
    tab = t; document.querySelectorAll('.cm-tabs [data-tab]').forEach(function (b) { b.setAttribute('aria-selected', b.dataset.tab === t ? 'true' : 'false'); });
    $('#cmFind').hidden = t !== 'find'; load();
    if (t === 'find') $('#cmQ').focus();
  }
  function load() {
    if (tab === 'chats') return call('inbox').then(function (r) {
      threads = r.threads; badge($('#bReq'), r.requests); badge($('#bChats'), threads.reduce(function (s, t) { return s + t.unread; }, 0));
      list.innerHTML = threads.length ? threads.map(function (t) {
        return '<li><button type="button" class="cm-row' + (open === t.id ? ' cur' : '') + (t.unread ? ' unread' : '') + '" data-chat="' + t.id + '">' + av(t)
          + '<span class="cm-rt"><b>' + esc(t.name) + '</b><small>' + (t.last ? (t.last.mine ? 'You: ' : '') + esc(t.last.body) : esc(sub(t))) + '</small></span>'
          + '<span class="cm-rm">' + (t.last ? '<small>' + esc(t.last.ago) + '</small>' : '') + (t.unread ? '<span class="cm-badge">' + t.unread + '</span>' : '') + '</span></button></li>';
      }).join('') : '<li class="cm-none">No chats yet. Use <b>Find people</b> to add friends; once they accept, you can message them.</li>';
    }).catch(function () {});
    if (tab === 'requests') return call('requests').then(function (r) {
      badge($('#bReq'), r.incoming.length);
      var row = function (p, out) { return '<li class="cm-prow"><button type="button" class="cm-row" data-person="' + p.id + '">' + av(p) + '<span class="cm-rt"><b>' + esc(p.name) + '</b><small>' + esc(sub(p)) + '</small></span></button><div class="cm-pact">'
        + (out ? '<button class="btn sm line" data-do="cancel" data-id="' + p.id + '">Cancel</button>' : '<button class="btn sm" data-do="respond" data-accept="1" data-id="' + p.id + '">Accept</button><button class="btn sm line" data-do="respond" data-id="' + p.id + '">Decline</button>') + '</div></li>'; };
      list.innerHTML = '<li class="cm-sec">Waiting for you</li>' + (r.incoming.length ? r.incoming.map(function (p) { return row(p, false); }).join('') : '<li class="cm-none">No requests.</li>')
        + (r.outgoing.length ? '<li class="cm-sec">You asked</li>' + r.outgoing.map(function (p) { return row(p, true); }).join('') : '');
    }).catch(function () {});
    if (tab === 'find') return search();
  }
  var st = null;
  function search() {
    var q = $('#cmQ').value.trim(), sc = $('#cmScope').value;
    return call('search&q=' + encodeURIComponent(q) + '&scope=' + sc).then(function (r) {
      list.innerHTML = r.people.length ? r.people.map(function (p) {
        return '<li class="cm-prow"><button type="button" class="cm-row" data-person="' + p.id + '">' + av(p) + '<span class="cm-rt"><b>' + esc(p.name) + '</b><small>' + esc(sub(p)) + (p.department ? ' · ' + esc(p.department) : '') + '</small></span></button><div class="cm-pact">' + rel(p) + '</div></li>';
      }).join('') : '<li class="cm-none">' + (q ? 'Nobody found with that name.' : 'No one here yet. Invite your classmates to join!') + '</li>';
    }).catch(function () {});
  }
  $('#cmQ').addEventListener('input', function () { clearTimeout(st); st = setTimeout(search, 250); });
  $('#cmScope').addEventListener('change', search);
  $('#cmFind').addEventListener('submit', function (e) { e.preventDefault(); search(); });
  document.querySelectorAll('.cm-tabs [data-tab]').forEach(function (b) { b.addEventListener('click', function () { showTab(b.dataset.tab); }); });

  /* ---------- a conversation ---------- */
  function day(iso) { var d = new Date(iso); return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: d.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined }); }
  function time(iso) { return new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }); }
  var lastDay = '';
  function bubble(m) {
    var d = day(m.at), h = '';
    if (d !== lastDay) { h += '<div class="cm-day"><span>' + esc(d) + '</span></div>'; lastDay = d; }
    if (m.file) { files[m.file.id] = m.file; return h + fileBubble(m); }
    return h + '<div class="cm-msg' + (m.mine ? ' mine' : '') + '" data-mid="' + m.id + '"><p>' + esc(m.body).replace(/\n/g, '<br>') + '</p><small>' + esc(time(m.at)) + (m.mine ? '<span class="cm-seen" hidden> · Seen</span>' : '') + '</small></div>';
  }
  function seen(upto) { msgs.querySelectorAll('.cm-msg.mine').forEach(function (b) { var s = b.querySelector('.cm-seen'); if (s) s.hidden = true; }); var last = null; msgs.querySelectorAll('.cm-msg.mine').forEach(function (b) { if (+b.dataset.mid <= upto) last = b; }); if (last) last.querySelector('.cm-seen').hidden = false; }
  function openChat(id) {
    open = id; lastId = 0; lastDay = ''; clearTimeout(timer);
    empty.hidden = true; person.hidden = true; thread.hidden = false; cm.classList.add('in-thread'); msgs.innerHTML = '<p class="cm-none">Loading…</p>';
    call('messages&with=' + id).then(function (r) {
      keep(r); var p = r.person; $('#thAv').outerHTML = av(p).replace('class="cm-av"', 'class="cm-av" id="thAv"'); $('#thName').textContent = p.name; $('#thSub').textContent = (p.online ? 'Online · ' : '') + sub(p);
      var canSend = p.relation === 'friends'; $('#cmCompose').hidden = !canSend;
      msgs.innerHTML = (r.more ? '<button class="linkbtn cm-more" type="button" data-older>Show earlier messages</button>' : '')
        + (r.messages.length ? r.messages.map(bubble).join('') : '<p class="cm-none">' + (canSend ? 'Say hello to ' + esc(p.name) + '. Keep it friendly and about study.' : 'You are not friends any more, so you cannot send messages.') + '</p>');
      if (r.messages.length) { firstId = r.messages[0].id; lastId = r.messages[r.messages.length - 1].id; }
      seen(r.seen_upto); decorate(); msgs.scrollTop = msgs.scrollHeight; if (canSend && matchMedia('(min-width:720px)').matches) $('#cmText').focus({ preventScroll: true });
      load(); poll();
    }).catch(function (e) { msgs.innerHTML = '<p class="cm-none">' + esc(e.message) + '</p>'; });
  }
  function poll() {
    clearTimeout(timer); if (!open) return;
    timer = setTimeout(function () {
      if (document.hidden) return poll();
      var id = open;
      call('messages&with=' + id + '&after=' + lastId).then(function (r) {
        if (open !== id) return;
        keep(r);
        if (r.messages.length) {
          var near = msgs.scrollHeight - msgs.scrollTop - msgs.clientHeight < 80, none = msgs.querySelector('.cm-none'); if (none) none.remove();
          r.messages.forEach(function (m) { if (!msgs.querySelector('[data-mid="' + m.id + '"]')) msgs.insertAdjacentHTML('beforeend', bubble(m)); });
          lastId = r.messages[r.messages.length - 1].id; decorate(); if (near) msgs.scrollTop = msgs.scrollHeight;
        }
        seen(r.seen_upto); poll();
      }).catch(function () { poll(); });
    }, 3000);
  }
  msgs.addEventListener('click', function (e) {
    if (!e.target.closest('[data-older]') || !open) return;
    var btn = e.target.closest('[data-older]'), h = msgs.scrollHeight;
    call('messages&with=' + open + '&before=' + firstId).then(function (r) {
      keep(r); lastDay = ''; var html = r.messages.map(bubble).join(''); btn.remove();
      msgs.insertAdjacentHTML('afterbegin', (r.more ? '<button class="linkbtn cm-more" type="button" data-older>Show earlier messages</button>' : '') + html);
      if (r.messages.length) firstId = r.messages[0].id; decorate(); msgs.scrollTop = msgs.scrollHeight - h;
    });
  });
  var text = $('#cmText');
  function grow() { text.style.height = 'auto'; text.style.height = Math.min(160, text.scrollHeight) + 'px'; }
  text.addEventListener('input', grow);
  text.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); $('#cmCompose').requestSubmit(); } });
  $('#cmCompose').addEventListener('submit', function (e) {
    e.preventDefault(); var body = text.value.trim(); if (!body || !open) return;
    var b = $('.cm-send'); b.disabled = true;
    call('send', { to: open, body: body }).then(function (r) {
      var none = msgs.querySelector('.cm-none'); if (none) none.remove();
      msgs.insertAdjacentHTML('beforeend', bubble(r.message)); lastId = Math.max(lastId, r.message.id); msgs.scrollTop = msgs.scrollHeight;
      text.value = ''; grow(); b.disabled = false; text.focus(); if (tab === 'chats') load();
    }).catch(function (x) { b.disabled = false; if (window.toast) toast(x.message); });
  });
  $('#cmBack').addEventListener('click', function () { open = null; clearTimeout(timer); cm.classList.remove('in-thread'); thread.hidden = true; person.hidden = true; empty.hidden = false; load(); });

  /* ---------- a person's profile ---------- */
  function showPerson(id) {
    open = null; clearTimeout(timer);
    call('person&id=' + id).then(function (r) {
      var p = r.person; empty.hidden = true; thread.hidden = true; person.hidden = false; cm.classList.add('in-thread');
      person.innerHTML = '<button class="icobtn cm-back" type="button" data-back aria-label="Back"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></button>'
        + '<div class="cm-pcard">' + av(p).replace('cm-av', 'cm-av xl') + '<h2>' + esc(p.name) + '</h2><p class="note">' + esc(sub(p)) + '</p>'
        + '<dl class="kv"><dt>School</dt><dd>' + esc(p.school_name || '–') + '</dd>' + (p.department ? '<dt>Field</dt><dd>' + esc(p.department) + '</dd>' : '') + '</dl>'
        + (p.bio ? '<p class="cm-bio">' + esc(p.bio) + '</p>' : '') + '<div class="rowact">' + rel(p) + '</div>'
        + '<div class="cm-safety" hidden></div><p class="cm-pfoot"><button class="linkbtn" type="button" data-act2="report" data-id="' + p.id + '">Report</button> · <button class="linkbtn" type="button" data-act2="block" data-id="' + p.id + '">Block</button></p></div>';
      if (p.relation === 'friends') safety(p);
    }).catch(function (e) { if (window.toast) toast(e.message); });
  }
  person.addEventListener('click', function (e) { if (e.target.closest('[data-back]')) $('#cmBack').click(); });

  /* ---------- actions anywhere in the community ---------- */
  function act(what, id, extra) {
    return call(what, Object.assign({ id: id }, extra || {})).then(function (r) {
      if (r.message && window.toast) toast(r.message);
      if (r.relation === 'friends' && what !== 'request' && window.toast) toast('You are now friends. Say hello!');
      if (!person.hidden) showPerson(id); load(); return r;
    }).catch(function (x) { if (window.toast) toast(x.message); });
  }
  function report(id, mid) {
    openSheet('Report', '<form class="card mform" id="cmReport"><p class="note">Tell the PrepDeck team what happened. They will not tell this person who reported them.</p>'
      + '<div class="field"><label for="rpw">What happened?</label><textarea class="in" id="rpw" name="reason" rows="4" maxlength="500" required placeholder="e.g. harassment, spam, sharing answers during an exam, a fake account"></textarea></div>'
      + '<label class="chk"><input type="checkbox" name="block" value="1" checked>Also block this person</label><p class="err" role="alert"></p><button class="btn danger" type="submit">Send report</button></form>');
    $('#cmReport').addEventListener('submit', function (e) {
      e.preventDefault(); var d = Object.fromEntries(new FormData(e.target));
      call('report', { id: id, message_id: mid || 0, reason: d.reason, block: d.block ? 1 : 0 }).then(function (r) {
        closeSheet(); if (window.toast) toast(r.message); if (d.block) $('#cmBack').click(); else load();
      }).catch(function (x) { $('#cmReport .err').textContent = x.message; });
    });
  }
  function block(id) { if (!confirm('Block this person? You will not see each other in the community, and your friendship ends.')) return; act('block', id).then(function () { $('#cmBack').click(); }); }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-chat]'); if (t && cm.contains(t)) { e.preventDefault(); openChat(+t.dataset.chat); return; }
    t = e.target.closest('[data-person]'); if (t && cm.contains(t)) { showPerson(+t.dataset.person); return; }
    t = e.target.closest('[data-do]'); if (t && cm.contains(t)) { t.disabled = true; act(t.dataset.do, +t.dataset.id, t.dataset.do === 'respond' ? { accept: t.dataset.accept ? 1 : 0 } : null); return; }
    t = e.target.closest('[data-act2]'); if (t) { t.dataset.act2 === 'report' ? report(+t.dataset.id) : block(+t.dataset.id); return; }
    t = e.target.closest('#thMenu [data-act]'); if (t && open) {
      var id = open, a = t.dataset.act; document.getElementById('thMenu').hidden = true;
      if (a === 'profile') showPerson(id);
      else if (a === 'report') report(id);
      else if (a === 'block') block(id);
      else if (a === 'unfriend' && confirm('Remove this friend? You can still read your old messages but not send new ones.')) act('unfriend', id).then(function () { openChat(id); });
    }
  });

  /* ---------- profile sheet, lock ---------- */
  $('#cmProfileBtn').addEventListener('click', function () {
    openSheet('Profile and privacy', document.getElementById('tpl-cmprofile').innerHTML);
    var f = $('#cmProfile'), bl = $('[data-blocked]', f);
    call('blocked').then(function (r) {
      bl.innerHTML = r.people.length ? r.people.map(function (p) { return '<li>' + av(p) + '<span>' + esc(p.name) + '</span><button class="btn sm line" type="button" data-unblock="' + p.id + '">Unblock</button></li>'; }).join('') : '<li class="note">Nobody.</li>';
    });
    bl.addEventListener('click', function (e) { var b = e.target.closest('[data-unblock]'); if (b) call('unblock', { id: +b.dataset.unblock }).then(function () { b.closest('li').remove(); }); });
    f.addEventListener('submit', function (e) {
      e.preventDefault(); var d = Object.fromEntries(new FormData(f));
      call('profile', { display_name: d.display_name, bio: d.bio, discoverable: d.discoverable ? 1 : 0 }).then(function () { location.reload(); }).catch(function (x) { $('.err', f).textContent = x.message; });
    });
    $('[data-leave]', f).addEventListener('click', function () {
      if (!confirm('Leave the PrepDeck Community? Your profile, friends and requests are removed.')) return;
      call('leave', { password: $('[data-leave-pw]', f).value }).then(function (r) { location.href = r.redirect; }).catch(function (x) { $('.err', f).textContent = x.message; });
    });
  });
  $('#cmLock').addEventListener('click', function () { if (window.E2EE) E2EE.forget(); call('lock', {}).then(function () { location.reload(); }); });

  /* ---------- end-to-end encrypted files ---------- */
  var LOCK = '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';
  var DL = '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v12M7 11l5 5 5-5M4 20h16"/></svg>';
  function bytes(n) { return n < 1024 ? n + ' B' : n < 1048576 ? Math.round(n / 1024) + ' KB' : (n / 1048576).toFixed(1) + ' MB'; }
  function fileBubble(m) {
    return '<div class="cm-msg cm-filemsg' + (m.mine ? ' mine' : '') + '" data-mid="' + m.id + '" data-fid="' + m.file.id + '"><div class="cm-fcard"><span class="cm-fic">' + LOCK + '</span>'
      + '<span class="cm-fmeta"><b class="cm-fname">Encrypted file</b><small>' + bytes(Math.max(0, m.file.size - 16)) + ' · end-to-end encrypted</small></span>'
      + '<button type="button" class="icobtn cm-fdl" data-dl="' + m.file.id + '" aria-label="Download and decrypt" title="Download">' + DL + '</button></div><div class="cm-fprev"></div>'
      + '<small>' + esc(time(m.at)) + (m.mine ? '<span class="cm-seen" hidden> · Seen</span>' : '') + '</small></div>';
  }
  /** Decrypt each file's name (and preview small images) right here in the browser. */
  function decorate() {
    if (!myKid) return;
    msgs.querySelectorAll('[data-fid]:not([data-done])').forEach(function (el) {
      el.dataset.done = '1'; var f = files[el.dataset.fid], peer = open;
      E2EE.fileMeta(f, me, keyCache).then(function (meta) {
        el.querySelector('.cm-fname').textContent = meta.name; el.querySelector('.cm-fdl').setAttribute('aria-label', 'Download ' + meta.name);
        el.querySelector('small').textContent = bytes(meta.size) + ' · end-to-end encrypted';
        if (/^image\/(png|jpe?g|gif|webp)$/.test(meta.type) && meta.size < 6 * 1048576) E2EE.fileBlob(f, me, peer, keyCache).then(function (o) {
          var u = URL.createObjectURL(o.blob); el.querySelector('.cm-fprev').innerHTML = '<img alt="' + esc(o.name) + '" src="' + u + '">';
        }).catch(function () {});
      }).catch(function (x) { el.classList.add('cm-fbad'); el.querySelector('.cm-fname').textContent = 'Cannot open this file'; el.querySelector('small').textContent = x.message || 'It could not be decrypted.'; });
    });
  }
  msgs.addEventListener('click', function (e) {
    var b = e.target.closest('[data-dl]'); if (!b) return;
    if (!myKid) { keysBanner(); return; }
    b.disabled = true; var f = files[b.dataset.dl];
    E2EE.fileBlob(f, me, open, keyCache).then(function (o) {
      var a = document.createElement('a'); a.href = URL.createObjectURL(o.blob); a.download = o.name; document.body.appendChild(a); a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 4000); b.disabled = false;
    }).catch(function (x) { b.disabled = false; if (window.toast) toast(x.message); });
  });
  function upload(fd, prog) {
    return new Promise(function (res, rej) {
      var x = new XMLHttpRequest(); x.open('POST', PDBASE + 'api/community.php?a=upload'); x.setRequestHeader('X-CSRF-Token', csrf());
      x.upload.onprogress = function (e) { if (e.lengthComputable) prog(e.loaded / e.total); };
      x.onload = function () { var j; try { j = JSON.parse(x.responseText); } catch (e) { j = { ok: false, error: 'Upload failed. Try again.' }; } if (x.status === 423) location.reload(); j.ok ? res(j) : rej(new Error(j.error || 'Upload failed.')); };
      x.onerror = function () { rej(new Error('Network error. Please check your connection.')); }; x.send(fd);
    });
  }
  $('#cmAttach').addEventListener('click', function () { if (!myKid) { keysBanner(); return; } $('#cmFile').click(); });
  $('#cmFile').addEventListener('change', function () {
    var file = this.files[0], to = open; this.value = ''; if (!file || !to) return;
    if (file.size > maxFile) { if (window.toast) toast('Files can be up to ' + Math.round(maxFile / 1048576) + ' MB.'); return; }
    var bar = $('#cmUplBar'), up = $('#cmUpl'); up.hidden = false; $('#cmUplName').textContent = 'Encrypting ' + file.name + '…'; bar.style.width = '0';
    call('peerkey&id=' + to).then(function (r) {
      if (!r.key) throw new Error('Your friend has not opened the community since encrypted files arrived. Ask them to open it once, then try again.');
      return E2EE.encryptFile(file, me, to, myKid, r.key);
    }).then(function (pk) {
      $('#cmUplName').textContent = 'Sending ' + file.name + ' (encrypted)…';
      var fd = new FormData(); fd.append('to', to); Object.keys(pk.fields).forEach(function (k) { fd.append(k, pk.fields[k]); }); fd.append('file', pk.blob, 'encrypted.bin');
      return upload(fd, function (p) { bar.style.width = Math.round(p * 100) + '%'; });
    }).then(function (r) {
      up.hidden = true; keep(r); if (open !== to) return;
      var none = msgs.querySelector('.cm-none'); if (none) none.remove();
      msgs.insertAdjacentHTML('beforeend', bubble(r.message)); lastId = Math.max(lastId, r.message.id); decorate(); msgs.scrollTop = msgs.scrollHeight; if (tab === 'chats') load();
    }).catch(function (x) { up.hidden = true; if (window.toast) toast(x.message); });
  });
  /** Keys not unlocked on this device (another session, or browser storage cleared): ask for the password here. */
  function keysBanner(why) {
    var b = $('#cmKeys'); b.hidden = false;
    if (!window.E2EE || !E2EE.supported) { b.innerHTML = '<p>' + LOCK + ' This browser cannot open end-to-end encrypted files. Try an up-to-date Chrome, Edge, Firefox or Safari.</p>'; return; }
    b.innerHTML = '<form class="cm-keysf"><p>' + LOCK + ' <b>Encrypted files are locked on this device.</b> Enter your PrepDeck password to open and share them.</p>'
      + '<div class="inline"><label class="vh" for="cmKp">Password</label><input class="in" id="cmKp" type="password" autocomplete="current-password" required><button class="btn sm" type="submit">Unlock files</button></div><p class="err" role="alert"></p></form>';
    var f = $('.cm-keysf', b);
    f.addEventListener('submit', function (e) {
      e.preventDefault(); var pw = $('#cmKp').value, err = $('.err', f); err.textContent = 'Unlocking…';
      // the server checks the password first, so a typo is not mistaken for a changed password
      call('unlock', { password: pw }).then(function (r) { esid = cm.dataset.esid = r.esid; return E2EE.setup(me, pw, esid); }).then(function (st) {
        if (st === 'stale') { staleKeys(b, me, pw, esid, keysReady); return; }
        keysReady();
      }).catch(function (x) { err.textContent = x.message; });
    });
    if (why !== 'quiet') $('#cmKp').focus();
  }
  function keysReady() {
    if (!window.E2EE || !E2EE.supported) { keysBanner('quiet'); return; }
    E2EE.ready(me, esid).then(function (k) {
      myKid = k;
      if (!k) return keysBanner('quiet');
      $('#cmKeys').hidden = true; msgs.querySelectorAll('[data-fid][data-done]').forEach(function (el) { el.removeAttribute('data-done'); el.classList.remove('cm-fbad'); }); decorate();
    }).catch(function () { keysBanner('quiet'); });
  }
  function safety(p) {
    if (!myKid) return;
    Promise.all([call('peerkey&id=' + p.id), call('keys')]).then(function (rs) {
      var peer = rs[0].key, mine = rs[1].keys.filter(function (k) { return k.active; })[0], box = person.querySelector('.cm-safety');
      if (!peer || !mine || !box) return;
      return E2EE.safetyCode(mine.public_key, peer.public_key).then(function (code) {
        box.hidden = false; box.innerHTML = '<b>' + LOCK + ' Encryption code</b><code>' + esc(code) + '</code><small>Files you share with ' + esc(p.name) + ' are end-to-end encrypted. To be sure, compare this code with the one on their screen: if they match, only the two of you can open your files.</small>';
      });
    }).catch(function () {});
  }
  keysReady();

  // open ?with=<id> straight away (links from elsewhere), then keep the lists fresh
  var w = +new URLSearchParams(location.search).get('with'); if (w) openChat(w);
  load();
  listTimer = setInterval(function () { if (!document.hidden) load(); }, 15000);
})();
