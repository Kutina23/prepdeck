// Sign in / create account: tab toggle, live checks, AJAX submit.
(function () {
  var tabs = document.querySelectorAll('.toggle-tab'), forms = document.querySelectorAll('.auth-form');
  function show(id) {
    tabs.forEach(function (t) { t.setAttribute('aria-selected', t.dataset.form === id ? 'true' : 'false'); });
    forms.forEach(function (f) { f.classList.toggle('active', f.id === id); });
    var foot = document.querySelector('.auth-foot a');
    if (foot) { foot.href = PDBASE + (id === 'login' ? 'register.php' : 'login.php'); foot.innerHTML = id === 'login' ? 'No account? <strong>Create one</strong>' : 'Already have an account? <strong>Sign in</strong>'; }
    if (history.replaceState) history.replaceState(null, '', PDBASE + (id === 'login' ? 'login.php' : 'register.php') + location.search);
  }
  tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.form); }); });

  var next = new URLSearchParams(location.search).get('next') || '';
  function submit(form, path, extra, onFail) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var err = form.querySelector('.err'), btn = form.querySelector('button[type=submit]'); err.textContent = '';
      var data = Object.fromEntries(new FormData(form)); Object.assign(data, extra || {});
      btn.disabled = true;
      api(path, data).then(function (r) {
        if (r.twofa) { btn.disabled = false; twofaShow(); return; }
        location.href = r.redirect;
      }).catch(function (e) { err.textContent = e.message; btn.disabled = false; if (form.id === 'register') check(); if (onFail) onFail(e); });
    });
  }
  var login = document.getElementById('login'), reg = document.getElementById('register');
  if (login) submit(login, 'auth.php?a=login', { next: next });

  // two-step sign-in: the password was right, now the authenticator code (or a recovery code)
  var tf = document.getElementById('twofa'), tfCode = document.getElementById('twofa-code'), tfRecovery = false;
  function twofaMode(recovery) {
    tfRecovery = recovery; tfCode.value = '';
    tfCode.inputMode = recovery ? 'text' : 'numeric'; tfCode.maxLength = recovery ? 20 : 7; tfCode.placeholder = recovery ? 'xxxx-xxxx-xxxx' : '123 456';
    if (recovery) tfCode.removeAttribute('pattern'); else tfCode.setAttribute('pattern', '[0-9 ]{6,7}');
    tfCode.autocomplete = recovery ? 'off' : 'one-time-code';
    document.getElementById('twofaLabel').textContent = recovery ? 'Recovery code' : 'Authenticator code';
    document.getElementById('twofaHelp').textContent = recovery ? 'Enter one of the recovery codes you saved when you turned on two-step sign-in. Each code works once.' : 'Open your authenticator app and enter the 6-digit code for PrepDeck.';
    document.getElementById('twofaSwap').textContent = recovery ? 'Use my authenticator app instead' : 'Use a recovery code instead';
    tfCode.focus();
  }
  function twofaShow() {
    var tg = document.querySelector('.toggle'); if (tg) tg.hidden = true;
    forms.forEach(function (f) { f.classList.remove('active'); });
    tf.hidden = false; tf.classList.add('active'); tf.querySelector('.err').textContent = ''; twofaMode(false);
  }
  function twofaBack() {
    var tg = document.querySelector('.toggle'); if (tg) tg.hidden = false;
    tf.hidden = true; tf.classList.remove('active');
    login.querySelector('[name=password]').value = ''; show('login'); login.querySelector('[name=password]').focus();
  }
  if (tf) {
    submit(tf, 'auth.php?a=twofa', { next: next }, function (e) {
      if (e.status === 401 || e.status === 429) { var m = e.message; twofaBack(); login.querySelector('.err').textContent = m; }
      else { tfCode.select(); }
    });
    document.getElementById('twofaSwap').addEventListener('click', function () { twofaMode(!tfRecovery); });
    document.getElementById('twofaBack').addEventListener('click', twofaBack);
  }
  if (reg) {    submit(reg, 'auth.php?a=register');
    var btn = reg.querySelector('button[type=submit]'), dob = document.getElementById('dob'), dh = document.getElementById('dobHint'), lv = document.getElementById('level'), lh = document.getElementById('levelHint');
    var HINTS = { '100': 'First year. Welcome.', '200': 'Second year.', '300': 'Third year.', '400': 'Final-year papers and project work.', '500': 'Final level.', 'postgraduate': 'Postgraduate study.' };
    dob.max = new Date().toISOString().slice(0, 10);
    function age() { if (!dob.value) return null; var d = new Date(dob.value), n = new Date(), a = n.getFullYear() - d.getFullYear(); if (n < new Date(n.getFullYear(), d.getMonth(), d.getDate())) a--; return a; }
    function check() {
      var a = age(); dh.className = 'hint'; lh.textContent = HINTS[lv.value] || '';
      if (a === null) dh.textContent = ''; else if (a < 10 || a > 90) { dh.textContent = 'Check the date of birth.'; dh.className = 'hint bad'; }
      else if (a < 18) { dh.textContent = 'You can join, but PrepDeck AI is for students aged 18 and over.'; }
      else { dh.textContent = ''; }
      btn.disabled = !reg.checkValidity() || a === null || a < 10 || a > 90 || !sid.value;
    }

    // step 1: pick the school (search as you type); step 2: its departments load
    var sq = document.getElementById('school_q'), sid = document.getElementById('school_id'), sl = document.getElementById('school_list'), sh = document.getElementById('schoolHint');
    var dep = document.getElementById('department_id'), dn = document.getElementById('department_name'), timer = null, items = [], cur = -1;
    function closeList() { sl.hidden = true; sq.setAttribute('aria-expanded', 'false'); cur = -1; }
    function render(list) {
      items = list; cur = -1;
      sl.innerHTML = list.length ? list.map(function (s, i) { return '<button type="button" role="option" id="sopt' + i + '" data-i="' + i + '">' + esc(s.name) + (s.short ? ' (' + esc(s.short) + ')' : '') + '<small>' + esc([s.type, s.city, s.region].filter(Boolean).join(' · ')) + '</small></button>'; }).join('')
        : '<div class="none">No school found. Use "My school is not listed" below.</div>';
      sl.hidden = false; sq.setAttribute('aria-expanded', 'true');
    }
    function choose(s) {
      sid.value = s.id; sq.value = s.name; sh.textContent = [s.city, s.region].filter(Boolean).join(', '); closeList();
      dep.disabled = true; dep.innerHTML = '<option value="" disabled selected>Loading departments...</option>';
      api('schools.php?a=departments&school=' + s.id).then(function (r) {
        dep.innerHTML = '<option value="" disabled selected>Choose your department</option>' + r.departments.map(function (d) { return '<option value="' + d.id + '">' + esc(d.name) + '</option>'; }).join('') + '<option value="new">My department is not listed</option>';
        dep.disabled = false; if (!r.departments.length) { dep.value = 'new'; showNewDept(); } check();
      }).catch(function (e) { sh.textContent = e.message; });
    }
    function showNewDept() { var on = dep.value === 'new'; dn.hidden = !on; dn.required = on; if (on) dn.focus(); }
    sq.addEventListener('input', function () {
      sid.value = ''; sh.textContent = ''; clearTimeout(timer);
      var q = sq.value.trim(); if (q.length < 2) { closeList(); return; }
      timer = setTimeout(function () { api('schools.php?a=search&q=' + encodeURIComponent(q)).then(function (r) { render(r.schools); }).catch(function () {}); }, 200);
    });
    sq.addEventListener('keydown', function (e) {
      if (sl.hidden || !items.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); cur = (cur + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        sl.querySelectorAll('button').forEach(function (b, i) { b.setAttribute('aria-selected', i === cur ? 'true' : 'false'); }); sq.setAttribute('aria-activedescendant', 'sopt' + cur); }
      else if (e.key === 'Enter' && cur >= 0) { e.preventDefault(); choose(items[cur]); }
      else if (e.key === 'Escape') closeList();
    });
    sl.addEventListener('mousedown', function (e) { var b = e.target.closest('button[data-i]'); if (b) { e.preventDefault(); choose(items[+b.dataset.i]); } });
    sq.addEventListener('blur', function () { setTimeout(closeList, 150); });
    dep.addEventListener('change', showNewDept);

    // "my school is not listed": a short request form instead of the account form
    var rq = document.getElementById('schoolReq');
    document.getElementById('schoolMissing').addEventListener('click', function () { reg.classList.add('is-off'); rq.hidden = false; document.getElementById('rq_name').value = sq.value; document.getElementById('rq_email').value = reg.querySelector('[name=email]').value; });
    document.getElementById('schoolReqBack').addEventListener('click', function () { rq.hidden = true; reg.classList.remove('is-off'); });
    rq.addEventListener('submit', function (ev) {
      ev.preventDefault(); var err = rq.querySelector('.err'), ok = rq.querySelector('.auth-note'), b = rq.querySelector('button[type=submit]'); err.textContent = ''; b.disabled = true;
      api('schools.php?a=request', Object.fromEntries(new FormData(rq))).then(function (r) { ok.hidden = false; ok.textContent = r.message; }).catch(function (e) { err.textContent = e.message; b.disabled = false; });
    });
    reg.addEventListener('input', check); reg.addEventListener('change', check); check();
    if (new URLSearchParams(location.search).has('missing')) document.getElementById('schoolMissing').click();
  }

  // forgot password: the answer is the same whether or not the email exists
  var forgot = document.getElementById('forgot');
  if (forgot) forgot.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var err = forgot.querySelector('.err'), btn = forgot.querySelector('button[type=submit]'), done = document.getElementById('forgotDone');
    err.textContent = ''; btn.disabled = true;
    api('auth.php?a=forgot', Object.fromEntries(new FormData(forgot))).then(function (r) {
      forgot.classList.add('is-off'); done.hidden = false;
      document.getElementById('forgotMsg').textContent = r.message || 'Check your email for the reset link.';
      var link = document.getElementById('forgotLink');
      if (r.link) { link.hidden = false; link.textContent = 'No mail server on this install, so here is the link: ' + r.link; }
    }).catch(function (e) { err.textContent = e.message; btn.disabled = false; });
  });

  // reset password: token comes from the emailed link
  var reset = document.getElementById('reset');
  if (reset) submit(reset, 'auth.php?a=reset');
})();
