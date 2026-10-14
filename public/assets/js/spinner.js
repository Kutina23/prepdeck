// Page loader: hidden once the page is ready, shown again while navigating or submitting.
(function () {
  var el = document.getElementById('pageLoader'); if (!el) return;
  var showT, safetyT, loadT;
  function hide() { clearTimeout(showT); clearTimeout(safetyT); clearTimeout(loadT); el.classList.add('done'); }
  function show() {
    clearTimeout(showT); clearTimeout(safetyT); clearTimeout(loadT);
    showT = setTimeout(function () {
      el.classList.remove('done');
      safetyT = setTimeout(hide, 12000); // safety net so the loader never gets stuck on a failed navigation
    }, 250);
  }
  if (document.readyState === 'complete') hide(); else window.addEventListener('load', hide);
  window.addEventListener('pageshow', hide);
  loadT = setTimeout(hide, 4000);
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]'); if (!a || a.target || a.hasAttribute('download') || e.metaKey || e.ctrlKey) return;
    var h = a.getAttribute('href'); if (!h || h[0] === '#' || h.indexOf('javascript:') === 0) return; show();
  });
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented || f.hasAttribute('data-ajax') || f.hasAttribute('data-api') || f.hasAttribute('data-action')) return; // AJAX forms manage their own state
    show();
  });
})();
