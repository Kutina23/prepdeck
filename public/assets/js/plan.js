(function () {
  var vf = document.getElementById('vfy');
  if (vf) vf.onclick = async function () { var m = document.getElementById('vfym'); vf.disabled = true; try { var r = await api('payments.php?a=verify', { reference: vf.dataset.ref }); m.textContent = r.message; if (r.paid) setTimeout(function () { location.reload(); }, 1200); else vf.disabled = false; } catch (e) { m.textContent = e.message; vf.disabled = false; } };
  var box = document.getElementById('checkout'); if (!box) return;
  var pp = document.getElementById('pp'), msg = document.getElementById('paymsg'), cp = document.getElementById('cp'), cpm = document.getElementById('cpm');
  function cur() { return box.querySelector('[data-period][aria-pressed=true]'); }
  function show(amount, c) { var b = cur(); if (b) pp.innerHTML = (c || b.dataset.cur) + ' ' + Number(amount == null ? b.dataset.amount : amount).toFixed(2) + '<small> / ' + b.textContent.toLowerCase() + '</small>'; }
  box.addEventListener('click', function (e) { var b = e.target.closest('[data-period]'); if (!b) return; box.querySelectorAll('[data-period]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); }); show(); if (cp.value) quote(); });
  async function quote() { var b = cur(); try { var r = await api('payments.php?a=quote', { period: b.dataset.period, coupon: cp.value }); show(r.amount, r.currency); cpm.textContent = r.coupon_error || (r.percent_off ? r.percent_off + '% off applied' : ''); cpm.style.color = r.coupon_error ? 'var(--bad)' : ''; } catch (e) { cpm.textContent = e.message; } }
  document.getElementById('cpb').onclick = quote;
  document.getElementById('pay').onclick = async function () { var b = cur(), btn = this; btn.disabled = true; try { var r = await api('payments.php?a=checkout', { period: b.dataset.period, coupon: cp.value }); msg.hidden = false; msg.textContent = r.message; if (r.authorization_url) { location.href = r.authorization_url; return; } setTimeout(function () { location.reload(); }, 2500); } catch (e) { msg.hidden = false; msg.textContent = e.message; btn.disabled = false; } };
  show();
})();
