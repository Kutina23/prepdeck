// Show / hide password button. Runs on every password input on the page
// (sign in, create account, settings, reset) and adds the button next to it.
(function () {
  window.pwInit = function (root) { (root || document).querySelectorAll('input[type=password]').forEach(function (inp) {
    if (inp.dataset.pwReady) return;
    inp.dataset.pwReady = '1';
    var wrap = document.createElement('span');
    wrap.className = 'pw-wrap';
    inp.parentNode.insertBefore(wrap, inp);
    wrap.appendChild(inp);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-toggle';
    btn.setAttribute('aria-pressed', 'false');
    btn.setAttribute('aria-label', 'Show password');
    btn.title = 'Show password';
    btn.innerHTML =
      '<svg class="i-on" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>' +
      '<svg class="i-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 6.2A9.9 9.9 0 0 1 12 6c6.4 0 10 6 10 6a17 17 0 0 1-3.2 4M6.6 8.1A17 17 0 0 0 2 12s3.6 6 10 6c1.3 0 2.5-.2 3.5-.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';

    btn.addEventListener('click', function () {
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      btn.title = show ? 'Hide password' : 'Show password';
      wrap.classList.toggle('is-shown', show);
    });
    wrap.appendChild(btn);
  }); };
  window.pwInit(document);   // forms opened in a modal call pwInit(sheet) again
})();
