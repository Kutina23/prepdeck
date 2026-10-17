// Shell behaviour shared by the student dashboard and the admin area.
(function () {
  var $ = function (s, r) { return (r || document).querySelector(s); }, $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var app = $('#app');
  function sget(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function sset(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  var toastT;
  window.toast = function (msg) { var t = $('#toast'); if (!t) return; t.textContent = msg; t.classList.add('on'); clearTimeout(toastT); toastT = setTimeout(function () { t.classList.remove('on'); }, 3000); };

  /* ---- sheets ---- */
  var modal = $('#modal'), sheet = $('#sheet'), lastFocus = null;
  window.openSheet = function (title, html, size) {
    lastFocus = document.activeElement; $('#mt').textContent = title || ''; $('#mb').innerHTML = html;
    sheet.classList.toggle('wide', size === 'wide'); modal.hidden = false; document.body.classList.add('modal-open'); sheet.scrollTop = 0;
    var first = $('#mb input:not([type=hidden]):not([disabled]), #mb textarea, #mb select');
    if (first && matchMedia('(min-width:720px)').matches) first.focus(); else sheet.focus();
    if (window.pwInit) window.pwInit(sheet);
  };
  window.closeSheet = function () { modal.hidden = true; document.body.classList.remove('modal-open'); if (lastFocus && lastFocus.focus) lastFocus.focus(); };
  // data-sheet="name" opens <template id="tpl-name"> or <template id="name">
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-sheet]');
    if (b) {
      if (b.disabled) return;
      var n = b.dataset.sheet, t = document.getElementById('tpl-' + n) || document.getElementById(n);
      openSheet(b.dataset.title || '', t ? t.innerHTML : '', b.dataset.size);
      document.dispatchEvent(new CustomEvent('sheetopen', { detail: { sheet: n } }));
      if (n === 'bell') { sset('pd-bell', String(window.PD.latest || 0)); var d = $('.bell-btn .dot'); if (d) d.hidden = true; }
      return;
    }
    if (e.target.closest('#mx') || e.target === modal) closeSheet();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal && !modal.hidden && (!cfm || cfm.hidden)) closeSheet(); });
  var dot = $('.bell-btn .dot'); if (dot && window.PD && PD.latest && +sget('pd-bell') < PD.latest) dot.hidden = false;

  /* ---- Plus pop-up: [data-plus-lock="ai"] opens it for that tool instead of going to the locked page; [data-plus-auto] opens it on load ---- */
  var pw = $('#paywall');
  if (pw) {
    var pwCard = $('.pwcard', pw), pwFeat = {}, pwBack = null;
    try { pwFeat = JSON.parse(pw.dataset.features || '{}'); } catch (e) {}
    var pwOpen = function (key) {
      var f = pwFeat[key] || pwFeat.plus; if (!f) return;
      $('#pwt').textContent = f.t; $('#pwd').textContent = f.d; $('#pwi').innerHTML = f.i;
      if (modal && !modal.hidden) closeSheet();
      closeDrop(false);
      pwBack = document.activeElement; pw.hidden = false; document.body.classList.add('modal-open');
      var go = $('.pwgo', pw); (go && matchMedia('(min-width:720px)').matches ? go : pwCard).focus();
    };
    var pwClose = function () { pw.hidden = true; document.body.classList.remove('modal-open'); if (pwBack && pwBack.focus) pwBack.focus(); };
    window.openPlus = pwOpen;
    // capture phase, so the page loader and other link handlers never see the click
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-plus-lock]'); if (!b || e.metaKey || e.ctrlKey || e.shiftKey) return;
      e.preventDefault(); e.stopPropagation(); pwOpen(b.dataset.plusLock);
    }, true);
    pw.addEventListener('click', function (e) { if (e.target === pw || e.target.closest('[data-pw-close]')) pwClose(); });
    document.addEventListener('keydown', function (e) {
      if (pw.hidden) return;
      if (e.key === 'Escape') { pwClose(); return; }
      if (e.key === 'Tab') {   // keep focus inside the card
        var f = $$('a[href],button:not([disabled])', pwCard), first = f[0], last = f[f.length - 1];
        if (e.shiftKey && (document.activeElement === first || document.activeElement === pwCard)) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });
    var auto = $('[data-plus-auto]'); if (auto) pwOpen(auto.dataset.plusAuto);
  }

  /* ---- header drop panes: <button data-drop="id"> opens the panel with that id (notifications, account) ---- */
  var drop = null;
  function closeDrop(focusBtn) { if (!drop) return; drop.p.hidden = true; drop.b.setAttribute('aria-expanded', 'false'); if (focusBtn) drop.b.focus(); drop = null; }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-drop]');
    if (b) {
      var p = document.getElementById(b.dataset.drop), same = drop && drop.p === p;
      closeDrop(false); if (same || !p) return;
      p.hidden = false; b.setAttribute('aria-expanded', 'true'); drop = { b: b, p: p };
      if (p.id === 'bellpane') { sset('pd-bell', String((window.PD && PD.latest) || 0)); var d = $('.bell-btn .dot'); if (d) d.hidden = true; }
      var f = p.querySelector('a,button'); if (f && p.getAttribute('role') === 'menu') f.focus();
      return;
    }
    if (drop && !drop.p.contains(e.target)) closeDrop(false);
  });
  // arrow keys move through the account menu
  document.addEventListener('keydown', function (e) {
    if (!drop || (e.key !== 'ArrowDown' && e.key !== 'ArrowUp')) return;
    var items = $$('a,button', drop.p); if (!items.length) return; e.preventDefault();
    var i = items.indexOf(document.activeElement); items[(i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length].focus();
  });

  /* ---- sidebar as a slide-in drawer on phones and small tablets (the ☰ button in the header) ---- */
  var mb = $('#menuBtn'), scrim = $('#scrim'), side = $('#side');
  function drawer(on) {
    if (!app || !side) return;
    app.dataset.drawer = on ? 'open' : ''; if (scrim) scrim.hidden = !on; document.body.classList.toggle('drawer-open', on);
    if (mb) { mb.setAttribute('aria-expanded', on ? 'true' : 'false'); mb.setAttribute('aria-label', on ? 'Close menu' : 'Open menu'); }
    if (on) { var f = side.querySelector('[aria-current="page"]') || side.querySelector('a'); if (f) f.focus(); } else if (mb && side.contains(document.activeElement)) mb.focus();
  }
  if (mb) mb.addEventListener('click', function () { drawer(app.dataset.drawer !== 'open'); });
  if (scrim) scrim.addEventListener('click', function () { drawer(false); });
  if (side) side.addEventListener('click', function (e) { if (e.target.closest('a') && app.dataset.drawer === 'open') drawer(false); });
  addEventListener('resize', function () { if (app && app.dataset.drawer === 'open' && mb && getComputedStyle(mb).display === 'none') drawer(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (drop) closeDrop(true); else if (app && app.dataset.drawer === 'open') drawer(false);
  });

  /* ---- confirm overlay for destructive buttons: <button data-confirm="Delete this?"> ---- */
  var cfm = document.createElement('div'), cfmBtn = null, cfmResolve = null;
  cfm.className = 'cfm'; cfm.hidden = true;
  cfm.innerHTML = '<div class="box" role="alertdialog" aria-modal="true" aria-labelledby="cfmt" aria-describedby="cfmd"><span class="ic" aria-hidden="true">!</span><h2 id="cfmt">Please confirm</h2><p id="cfmd"></p><div class="mfoot"><button type="button" class="btn sm line" data-cfm="no">Cancel</button><button type="button" class="btn sm go" data-cfm="yes">Yes, continue</button></div></div>';
  document.body.appendChild(cfm);
  function cfmClose(go) {
    cfm.hidden = true; var b = cfmBtn, r = cfmResolve; cfmBtn = null; cfmResolve = null;
    if (r) { r(go); return; }
    if (!b) return;
    if (go) { b.dataset.cfmOk = '1'; b.click(); } else if (b.focus) b.focus();
  }
  window.confirmDialog = function (msg, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      cfmResolve = resolve; cfmBtn = null;
      $('#cfmt', cfm).textContent = opts.title || 'Please confirm';
      $('#cfmd', cfm).textContent = msg;
      $('[data-cfm=yes]', cfm).textContent = opts.ok || 'Yes, continue';
      cfm.hidden = false; $('[data-cfm=no]', cfm).focus();
    });
  };
  cfm.addEventListener('click', function (e) { var a = e.target.closest('[data-cfm]'); if (a) cfmClose(a.dataset.cfm === 'yes'); else if (e.target === cfm) cfmClose(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !cfm.hidden) { e.stopPropagation(); cfmClose(false); } }, true);
  document.addEventListener('click', function (e) {
    var c = e.target.closest('[data-confirm]'); if (!c || cfm.contains(c)) return;
    if (c.dataset.cfmOk) { delete c.dataset.cfmOk; return; }
    var msg = c.getAttribute('data-confirm'); if (!msg || msg === 'true') return; // empty data-confirm = no question
    e.preventDefault(); e.stopPropagation(); cfmBtn = c;
    $('#cfmt', cfm).textContent = c.dataset.confirmTitle || 'Please confirm';
    $('#cfmd', cfm).textContent = msg; $('[data-cfm=yes]', cfm).textContent = c.dataset.confirmYes || (c.textContent.trim() || 'Yes, continue');
    cfm.hidden = false; $('[data-cfm=no]', cfm).focus();
  }, true);

  /* ---- data tables ---- */
  $$('table.dtable').forEach(function (tb) {
    var heads = $$('thead th', tb), labels = heads.map(function (h) { return h.textContent.trim(); });
    $$('tbody tr', tb).forEach(function (tr) { $$('td', tr).forEach(function (td, i) { if (!td.hasAttribute('data-label')) td.setAttribute('data-label', labels[i] || ''); }); });
    heads.forEach(function (th, col) {
      if (!labels[col] || th.hasAttribute('data-nosort')) return;
      th.setAttribute('data-sortable', ''); th.tabIndex = 0;
      function sort() {
        var dir = th.getAttribute('aria-sort') === 'ascending' ? -1 : 1, body = tb.tBodies[0];
        heads.forEach(function (h) { h.removeAttribute('aria-sort'); }); th.setAttribute('aria-sort', dir > 0 ? 'ascending' : 'descending');
        var rows = $$('tr', body).filter(function (r) { return !r.classList.contains('dtempty'); });
        var key = function (r) { var c = r.cells[col]; if (!c) return ''; var v = c.hasAttribute('data-v') ? c.getAttribute('data-v') : c.textContent.trim(); var n = parseFloat(v.replace(/[^0-9.\-]/g, '')); return v !== '' && /^[\s\d.,\-%:]+$|^-?\d/.test(v) && !isNaN(n) ? n : v.toLowerCase(); };
        rows.sort(function (a, b) { var x = key(a), y = key(b); return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y))) * dir; });
        rows.forEach(function (r) { body.appendChild(r); });
      }
      th.addEventListener('click', sort); th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sort(); } });
    });
  });
  // quick filter of the rows on this page: <input data-dtfilter="#tableId">
  $$('[data-dtfilter]').forEach(function (inp) {
    var tb = $(inp.dataset.dtfilter); if (!tb) return;
    inp.addEventListener('input', function () { var q = inp.value.trim().toLowerCase(); $$('tbody tr', tb).forEach(function (r) { if (!r.classList.contains('dtempty')) r.hidden = q !== '' && r.textContent.toLowerCase().indexOf(q) < 0; }); });
  });
  // whole row opens its detail page: <tr data-href="...">
  document.addEventListener('click', function (e) {
    var tr = e.target.closest('tr[data-href]'); if (!tr || e.target.closest('a,button,input,select,textarea,label,form')) return;
    location.href = tr.dataset.href;
  });
  // select-all checkbox: <input type=checkbox data-checkall="ids[]">, bulk buttons get disabled while nothing is ticked
  function bulkSync(scope) {
    var n = $$('input[type=checkbox][name="ids[]"]:checked', scope).length;
    $$('[data-bulk]', scope).forEach(function (b) { b.disabled = n === 0; }); $$('[data-bulkcount]', scope).forEach(function (s) { s.textContent = n ? n + ' selected' : ''; });
  }
  $$('[data-checkall]').forEach(function (all) {
    var scope = all.closest('form') || document;
    all.addEventListener('change', function () { $$('input[type=checkbox][name="' + all.dataset.checkall + '"]', scope).forEach(function (c) { c.checked = all.checked; }); bulkSync(scope); });
    scope.addEventListener('change', function (e) { if (e.target.name === 'ids[]') bulkSync(scope); });
    bulkSync(scope);
  });
  // selects that submit their GET form straight away (rows per page)
  document.addEventListener('change', function (e) { if (e.target.matches('select[data-autosubmit]') && e.target.form) e.target.form.submit(); });

  /* ---- sidebar collapse (desktop) + tools rail ---- */
  var st = $('#side-toggle');
  if (st && app) {
    var sideSet = function (s) { app.dataset.side = s; st.setAttribute('aria-label', s === 'open' ? 'Collapse sidebar' : 'Expand sidebar'); sset('pd-side', s); };
    if (sget('pd-side') === 'closed') sideSet('closed');
    st.addEventListener('click', function () { sideSet(app.dataset.side === 'open' ? 'closed' : 'open'); });
  }
  var tt = $('#tt');
  if (tt && app) {
    var setTools = function (s, save) {
      app.dataset.tools = s; var l = s === 'open' ? 'Collapse study tools' : 'Expand study tools';
      tt.setAttribute('aria-expanded', s === 'open' ? 'true' : 'false'); tt.setAttribute('aria-label', l); tt.title = l; if (save) sset('pd-tools', s);
    };
    var sv = sget('pd-tools'); setTools(sv === 'open' || sv === 'closed' ? sv : (matchMedia('(min-width:1100px)').matches ? 'open' : 'closed'));
    tt.addEventListener('click', function () { setTools(app.dataset.tools === 'open' ? 'closed' : 'open', true); });
    $$('[data-expand]').forEach(function (b) { b.addEventListener('click', function () { setTools('open', true); var t = document.getElementById(b.dataset.expand); if (t) t.scrollIntoView({ block: 'nearest' }); }); });
  }

  /* ---- generic tabs: <div data-tabs><button data-tab="a">..</button> ... <section data-pane="a"> ---- */
  $$('[data-tabs]').forEach(function (box) {
    var key = box.dataset.tabs;
    function show(n) {
      $$('[data-tab]', box).forEach(function (b) { b.setAttribute('aria-selected', b.dataset.tab === n ? 'true' : 'false'); });
      $$('[data-pane]', box).forEach(function (p) { p.hidden = p.dataset.pane !== n; });
      if (history.replaceState) history.replaceState(null, '', '#' + n);
    }
    box.addEventListener('click', function (e) { var b = e.target.closest('[data-tab]'); if (b) show(b.dataset.tab); });
    var h = location.hash.slice(1), first = $('[data-tab]', box);
    show($('[data-tab="' + h + '"]', box) ? h : (first ? first.dataset.tab : ''));
  });

  /* ---- focus timer: free 15 / 5, Plus adjustable (server clamps what it records).
     State lives in localStorage (absolute end time), so the timer keeps running
     across page navigation and finishes even on another dashboard page ---- */
  /* ---- signing out wipes the community encryption keys unlocked in this browser (community/e2ee.js) ---- */
  document.addEventListener('click', function (e) { var a = e.target.closest('a[href*="logout"]'); if (a && window.indexedDB) try { indexedDB.deleteDatabase('pd-e2ee'); } catch (x) {} });

  /* ---- lecturer teaching-tools rail: open (labels) or closed (icons), remembered on this device ---- */
  var ltt = $('#ltt');
  if (ltt && app) {
    var setLt = function (s, save) {
      app.dataset.ltools = s; var l = s === 'open' ? 'Collapse teaching tools' : 'Expand teaching tools';
      ltt.setAttribute('aria-expanded', s === 'open' ? 'true' : 'false'); ltt.setAttribute('aria-label', l); ltt.title = l; if (save) sset('pd-ltools', s);
    };
    var lsv = sget('pd-ltools'); setLt(lsv === 'open' || lsv === 'closed' ? lsv : (matchMedia('(min-width:1400px)').matches ? 'open' : 'closed'));
    ltt.addEventListener('click', function () { setLt(app.dataset.ltools === 'open' ? 'closed' : 'open', true); });
  }

  var tb = $('#timerbox');
  if (tb) {
    var plus = tb.dataset.plus === '1', max = +tb.dataset.max, C = 276.46, mode = 'focus';
    var lens = { focus: plus ? 25 : Math.min(15, max), 'break': 5 }, left, run = null, endAt = 0, title0 = document.title, lastDone = 'focus';
    var PRE = { focus: [15, 25, 45, 60, 90], 'break': [5, 10, 15, 20] };
    if (plus) { var sf = +sget('pd-focus'), sb = +sget('pd-break'); if (sf >= 5 && sf <= max) lens.focus = Math.round(sf); if (sb >= 5 && sb <= 60) lens['break'] = Math.round(sb); }
    var ts = $('#ts'), tp = $('#tp'), tout = $('#tt2'), tmini = $('#tmini');
    var mins = function () { return lens[mode]; };
    var clock = function (s) { s = s === undefined ? left : s; var m = Math.floor(s / 60), x = s % 60; return (m < 10 ? '0' : '') + m + ':' + (x < 10 ? '0' : '') + x; };
    var title = function () { document.title = run ? clock() + ' · ' + title0 : title0; };
    var paint = function () { tout.textContent = clock(); tp.style.strokeDashoffset = (C * (1 - left / (mins() * 60))).toFixed(2); if (tmini) tmini.textContent = run ? clock() : ''; title(); };
    var labels = function () {
      $$('#seg button').forEach(function (b) { var m = b.dataset.mode; b.textContent = (m === 'focus' ? 'Focus ' : 'Break ') + lens[m]; b.setAttribute('aria-pressed', m === mode ? 'true' : 'false'); });
      if (!plus) return;
      var hi = mode === 'focus' ? max : 60; $('#lenv').textContent = mins() + ' min'; $('#dec').disabled = mins() <= 5; $('#inc').disabled = mins() >= hi;
      $('#presets').innerHTML = PRE[mode].filter(function (n) { return n <= hi; }).map(function (n) { return '<button type="button" class="pre" data-len="' + n + '" aria-pressed="' + (n === mins()) + '">' + n + '</button>'; }).join('');
    };
    var SKEY = 'pd-timer', DKEY = 'pd-timer-done';
    var save = function () { sset(SKEY, JSON.stringify({ mode: mode, running: !!run, end: run ? endAt : 0, left: left, len: mins() * 60 })); };
    var wipe = function () { try { localStorage.removeItem(SKEY); } catch (e) {} };
    var stop = function () { if (run) clearInterval(run); run = null; ts.textContent = 'Start'; title(); };
    var reset = function () { stop(); left = mins() * 60; labels(); paint(); };
    var setLen = function (n) { if (!plus) return; var hi = mode === 'focus' ? max : 60; n = Math.max(5, Math.min(hi, Math.round(n))); lens[mode] = n; sset(mode === 'focus' ? 'pd-focus' : 'pd-break', String(n)); reset(); };
    var chime = function () {
      try {
        var AC = window.AudioContext || window.webkitAudioContext; if (!AC) return;
        var x = new AC(); if (x.state === 'suspended') x.resume();
        var o = x.createOscillator(), g = x.createGain();
        o.type = 'sine'; o.frequency.value = 880;
        g.gain.setValueAtTime(0.0001, x.currentTime);
        g.gain.exponentialRampToValueAtTime(0.2, x.currentTime + 0.03);
        g.gain.exponentialRampToValueAtTime(0.0001, x.currentTime + 1);
        o.connect(g); g.connect(x.destination); o.start(); o.stop(x.currentTime + 1.1);
      } catch (e) {}
    };
    var tick = function () {
      left = Math.max(0, Math.ceil((endAt - Date.now()) / 1000));
      if (left <= 0) { complete(); return; }
      paint();
    };
    var complete = function () {
      var was = mode === 'focus', len = mins();
      stop();
      if (sget(DKEY) === String(endAt)) { wipe(); left = len * 60; labels(); paint(); return; } // another tab already handled this session
      sset(DKEY, String(endAt));
      wipe();
      left = len * 60; labels(); paint();
      lastDone = was ? 'focus' : 'break';
      if (was) api('focus.php', { minutes: len }).catch(function () {});
      chime();
      pomodoroAlert(was, len);
    };
    ts.addEventListener('click', function () {
      if (run) { left = Math.max(0, Math.ceil((endAt - Date.now()) / 1000)); stop(); paint(); save(); return; }
      ts.textContent = 'Pause';
      endAt = Date.now() + left * 1000;
      run = setInterval(tick, 500);
      save(); paint();
    });
    $('#tr').addEventListener('click', function () { wipe(); reset(); });
    $$('#seg button').forEach(function (b) { b.addEventListener('click', function () { mode = b.dataset.mode; wipe(); reset(); }); });
    if (plus) {
      $('#dec').addEventListener('click', function () { setLen(mins() - 5); }); $('#inc').addEventListener('click', function () { setLen(mins() + 5); });
      $('#presets').addEventListener('click', function (e) { var p = e.target.closest('[data-len]'); if (p) setLen(+p.dataset.len); });
    }

    /* ---- pomodoro completion alert overlay ---- */
    var pom = document.createElement('div');
    pom.className = 'pom'; pom.hidden = true;
    pom.innerHTML = '<div class="box" role="alertdialog" aria-modal="true" aria-labelledby="pomt" aria-describedby="pomd">' +
      '<span class="ic" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2M9 2h6"/></svg></span>' +
      '<h2 id="pomt"></h2><p id="pomd"></p>' +
      '<div class="pstats"><div class="pstat" id="poms1"></div><div class="pstat" id="poms2"></div></div>' +
      '<div class="mfoot"><button type="button" class="btn sm line" data-pom="again"></button><button type="button" class="btn sm go" data-pom="next"></button></div></div>';
    document.body.appendChild(pom);
    var pomClose = function () { pom.hidden = true; document.body.classList.remove('modal-open'); if (ts.focus) ts.focus(); };
    var pomodoroAlert = function (wasFocus, minutes) {
      var nextLen = lens[wasFocus ? 'break' : 'focus'];
      $('#pomt', pom).textContent = wasFocus ? 'Focus session complete' : 'Break over';
      $('#pomd', pom).textContent = wasFocus
        ? 'You stayed focused for ' + minutes + ' minute' + (minutes === 1 ? '' : 's') + '. The session is logged to your progress — now recharge.'
        : 'Your ' + minutes + ' minute break is done. Stretch, hydrate, and jump back in.';
      $('#poms1', pom).innerHTML = '<small>' + (wasFocus ? 'Completed' : 'Break finished') + '</small><b>' + (wasFocus ? 'Focus' : 'Break') + ' · ' + minutes + ' min</b>';
      $('#poms2', pom).innerHTML = '<small>Up next</small><b>' + (wasFocus ? 'Break' : 'Focus') + ' · ' + nextLen + ' min</b>';
      $('[data-pom=next]', pom).textContent = wasFocus ? 'Start ' + nextLen + ' min break' : 'Start focus';
      $('[data-pom=again]', pom).textContent = wasFocus ? 'Another focus round' : 'Rest a little longer';
      pom.hidden = false; document.body.classList.add('modal-open');
      var m = $('[data-pom=next]', pom); if (m.focus) m.focus();
    };
    var pomGo = function (which) {
      pomClose();
      mode = which === 'next' ? (lastDone === 'focus' ? 'break' : 'focus') : lastDone;
      wipe(); reset();
      ts.click();
    };
    pom.addEventListener('click', function (e) {
      var b = e.target.closest('[data-pom]');
      if (b) { pomGo(b.dataset.pom); return; }
      if (e.target === pom) pomClose();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !pom.hidden) { e.stopPropagation(); pomClose(); } }, true);

    /* ---- restore a timer that was running or paused before navigating away ---- */
    var restore = function () {
      var st = null; try { st = JSON.parse(sget(SKEY) || 'null'); } catch (e) {}
      if (run) { clearInterval(run); run = null; ts.textContent = 'Start'; }
      mode = 'focus';
      if (st && (st.mode === 'focus' || st.mode === 'break') && st.len >= 300) {
        mode = st.mode; lens[mode] = Math.round(st.len / 60); endAt = st.end || 0;
        if (st.running) {
          left = Math.max(0, Math.ceil((endAt - Date.now()) / 1000));
          if (left > 0) { ts.textContent = 'Pause'; run = setInterval(tick, 500); }
          else { left = lens[mode] * 60; labels(); paint(); setTimeout(complete, 400); return; } // finished while we were away
        } else {
          left = (st.left > 0 && st.left <= lens[mode] * 60) ? st.left : lens[mode] * 60;
        }
      } else {
        left = mins() * 60;
      }
      labels(); paint();
    };
    window.addEventListener('storage', function (e) { if (e.key === SKEY) restore(); });
    restore();
  }

  /* ---- quick notes (this device only) ---- */
  var qn = $('#notes');
  if (qn) {
    var v = sget('prepdeck-notes'); if (v) qn.value = v;
    qn.addEventListener('input', function () { sset('prepdeck-notes', qn.value); $('#ns').textContent = 'Saved on this device'; });
  }
})();
