// Shared helpers. Every request carries the CSRF token and the site base path.
const PDBASE = (document.querySelector('meta[name=base]') || {content: '/'}).content;
const csrf = () => (document.querySelector('meta[name=csrf]') || {content: ''}).content;
async function api(path, data, method, timeout) {
  const isForm = data instanceof FormData;
  const ms = timeout || (isForm ? 300000 : 60000);
  const ctrl = new AbortController();
  const to = setTimeout(function () { ctrl.abort(); }, ms);
  let res, j;
  try {
    res = await fetch(PDBASE + 'api/' + path, {
      method: method || (data ? 'POST' : 'GET'),
      headers: Object.assign({'X-CSRF-Token': csrf()}, isForm || !data ? {} : {'Content-Type': 'application/json'}),
      body: data ? (isForm ? data : JSON.stringify(data)) : undefined, credentials: 'same-origin', signal: ctrl.signal
    });
    j = await res.json().catch(() => ({ok: false, error: 'Server error. Please try again.'}));
  } catch (e) {
    clearTimeout(to);
    if (e.name === 'AbortError') throw Object.assign(new Error('Request timed out. Please try again.'), {status: 0});
    throw Object.assign(new Error('Network error. Please check your connection.'), {status: 0});
  }
  clearTimeout(to);
  if (!j.ok) throw Object.assign(new Error(j.error || 'Something went wrong.'), {status: res.status});
  return j;
}
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
