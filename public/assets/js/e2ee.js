// End-to-end encryption for community files. Everything here runs in the browser with Web Crypto; the server only
// stores public keys, password-wrapped private keys and encrypted bytes.
//
//  Keys    each member has an ECDH P-256 key pair made in their browser. The private key is stored on the server
//          only as AES-GCM ciphertext under a key derived from their PrepDeck password (PBKDF2-SHA-256, 600,000 rounds).
//          Unlocked private keys live in this browser's IndexedDB as non-extractable keys, tagged with the sign-in
//          session, and are wiped on lock / sign-out.
//  Files   each file gets a random AES-256-GCM key. The file and its name/type are encrypted with it, and the file key is
//          wrapped with a key both people can derive: ECDH(my private, their public) -> HKDF-SHA-256.
(function () {
  var C = window.crypto && window.crypto.subtle, ITER = 600000, enc = new TextEncoder(), dec = new TextDecoder();
  var supported = !!(C && window.indexedDB);
  function b64(buf) { var s = '', a = new Uint8Array(buf); for (var i = 0; i < a.length; i += 0x8000) s += String.fromCharCode.apply(null, a.subarray(i, i + 0x8000)); return btoa(s); }
  function unb64(s) { var b = atob(s), a = new Uint8Array(b.length); for (var i = 0; i < b.length; i++) a[i] = b.charCodeAt(i); return a; }
  function rand(n) { return crypto.getRandomValues(new Uint8Array(n)); }
  function join(a, b) { var o = new Uint8Array(a.length + b.byteLength); o.set(a, 0); o.set(new Uint8Array(b), a.length); return o; }
  function hex(buf) { return Array.prototype.map.call(new Uint8Array(buf), function (x) { return ('0' + x.toString(16)).slice(-2); }).join(''); }
  async function seal(key, data, aad) { var iv = rand(12); return b64(join(iv, await C.encrypt({ name: 'AES-GCM', iv: iv, additionalData: enc.encode(aad) }, key, data))); }
  async function open(key, packed, aad) { var p = unb64(packed); return C.decrypt({ name: 'AES-GCM', iv: p.slice(0, 12), additionalData: enc.encode(aad) }, key, p.slice(12)); }

  /* ---------- IndexedDB: unlocked private keys for this session ---------- */
  function db() {
    return new Promise(function (res, rej) { var r = indexedDB.open('pd-e2ee', 1); r.onupgradeneeded = function () { r.result.createObjectStore('keys', { keyPath: 'k' }); }; r.onsuccess = function () { res(r.result); }; r.onerror = function () { rej(r.error); }; });
  }
  async function idb(mode, fn) { var d = await db(); return new Promise(function (res, rej) { var t = d.transaction('keys', mode), s = t.objectStore('keys'), out = fn(s); t.oncomplete = function () { d.close(); res(out && out.result !== undefined ? out.result : out); }; t.onerror = function () { d.close(); rej(t.error); }; }); }
  var mem = {};   // kid -> CryptoKey, for this page
  async function remember(uid, kid, key, esid) { mem[kid] = key; await idb('readwrite', function (s) { s.put({ k: uid + ':' + kid, uid: uid, kid: kid, key: key, esid: esid }); }); }
  async function loadAll(uid, esid) {
    var all = await idb('readonly', function (s) { return s.getAll(); }) || [], stale = [];
    all.forEach(function (r) { if (r.uid === uid && r.esid === esid) mem[r.kid] = r.key; else stale.push(r.k); });
    if (stale.length) await idb('readwrite', function (s) { stale.forEach(function (k) { s.delete(k); }); });   // keys from an earlier sign-in
  }

  /* ---------- key pairs ---------- */
  async function kek(password, salt, iter) {
    var base = await C.importKey('raw', enc.encode(password), 'PBKDF2', false, ['deriveKey']);
    return C.deriveKey({ name: 'PBKDF2', salt: salt, iterations: iter, hash: 'SHA-256' }, base, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
  }
  async function wrapPrivate(uid, pkcs8, password) { var salt = rand(16); return { wrapped: await seal(await kek(password, salt, ITER), pkcs8, 'prepdeck-key|' + uid), salt: b64(salt), iterations: ITER }; }
  async function unwrapPrivate(uid, k, password) {
    var raw = await open(await kek(password, unb64(k.salt), k.iterations), k.wrapped, 'prepdeck-key|' + uid);   // throws when the password is not the one it was wrapped with
    return { raw: raw, key: await C.importKey('pkcs8', raw, { name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveBits']) };
  }
  async function newKeys(uid, password, esid) {
    var pair = await C.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);
    var w = await wrapPrivate(uid, await C.exportKey('pkcs8', pair.privateKey), password);
    var r = await api('community.php?a=keys_new', { public_key: b64(await C.exportKey('spki', pair.publicKey)), wrapped: w.wrapped, salt: w.salt, iterations: w.iterations });
    var priv = await C.importKey('pkcs8', await C.exportKey('pkcs8', pair.privateKey), { name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveBits']);
    await remember(uid, r.id, priv, esid);
  }

  /** After joining / unlocking: unlock my keys with the password ('ok'), make the first pair, or report 'stale' (password changed since). */
  async function setup(uid, password, esid) {
    var keys = (await api('community.php?a=keys')).keys, active = null;
    if (!keys.length) { await newKeys(uid, password, esid); return 'ok'; }
    for (var i = 0; i < keys.length; i++) {
      try { var u = await unwrapPrivate(uid, keys[i], password); await remember(uid, keys[i].id, u.key, esid); if (keys[i].active) active = keys[i].id; } catch (e) { /* wrapped with another password */ }
    }
    return active ? 'ok' : 'stale';
  }
  /** Password changed: open the keys with the previous password and wrap them again with the current one. False if the old password is wrong. */
  async function rewrap(uid, oldPw, newPw, esid) {
    var keys = (await api('community.php?a=keys')).keys, out = [], ok = false;
    for (var i = 0; i < keys.length; i++) {
      try { var u = await unwrapPrivate(uid, keys[i], oldPw), w = await wrapPrivate(uid, u.raw, newPw); out.push({ id: keys[i].id, wrapped: w.wrapped, salt: w.salt, iterations: w.iterations }); await remember(uid, keys[i].id, u.key, esid); if (keys[i].active) ok = true; } catch (e) {}
    }
    if (!ok) return false;
    await api('community.php?a=keys_rewrap', { keys: out }); return true;
  }
  /** Are my current keys unlocked in this browser for this session? */
  async function ready(uid, esid) {
    if (!supported) return false;
    await loadAll(uid, esid);
    var keys = (await api('community.php?a=keys')).keys, act = keys.filter(function (k) { return k.active; })[0];
    return !!(act && mem[act.id]) ? act.id : false;
  }
  async function forget() { mem = {}; if (supported) try { indexedDB.deleteDatabase('pd-e2ee'); } catch (e) {} }

  /* ---------- files ---------- */
  async function pairKey(myKid, theirPub, sk, rk) {
    var mine = mem[myKid]; if (!mine) throw new Error('This file was shared with an older key of yours, so it cannot be opened on this account any more.');
    var pub = await C.importKey('spki', unb64(theirPub), { name: 'ECDH', namedCurve: 'P-256' }, false, []);
    var bits = await C.deriveBits({ name: 'ECDH', public: pub }, mine, 256), base = await C.importKey('raw', bits, 'HKDF', false, ['deriveKey']);
    return C.deriveKey({ name: 'HKDF', hash: 'SHA-256', salt: enc.encode('prepdeck-dm-v1'), info: enc.encode(Math.min(sk, rk) + ':' + Math.max(sk, rk)) }, base, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
  }
  /** Encrypt a File for a friend. Returns the encrypted blob and the fields the server stores. */
  async function encryptFile(file, me, peer, myKid, peerKey) {
    var data = await file.arrayBuffer(), fk = await C.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']), iv = rand(12);
    var ct = await C.encrypt({ name: 'AES-GCM', iv: iv, additionalData: enc.encode('prepdeck-file|' + me + '|' + peer) }, fk, data);
    var meta = await seal(fk, enc.encode(JSON.stringify({ name: file.name.slice(0, 200), type: (file.type || 'application/octet-stream').slice(0, 100), size: file.size })), 'prepdeck-meta');
    var pk = await pairKey(myKid, peerKey.public_key, myKid, peerKey.id);
    var wrapped = await seal(pk, await C.exportKey('raw', fk), 'prepdeck-wrap|' + myKid + '|' + peerKey.id);
    return { blob: new Blob([ct]), fields: { meta: meta, file_iv: b64(iv), wrapped_key: wrapped, sender_key_id: myKid, recipient_key_id: peerKey.id, sha256: hex(await C.digest('SHA-256', ct)) } };
  }
  var fkCache = {};
  async function fileKey(f, me, keys) {
    if (fkCache[f.id]) return fkCache[f.id];
    var mineIsSender = f.from === me, myKid = mineIsSender ? f.sk : f.rk, theirKid = mineIsSender ? f.rk : f.sk;
    if (!keys[theirKid]) throw new Error('The other person\'s key is missing.');
    var pk = await pairKey(myKid, keys[theirKid], f.sk, f.rk);
    return fkCache[f.id] = await C.importKey('raw', await open(pk, f.wrapped, 'prepdeck-wrap|' + f.sk + '|' + f.rk), 'AES-GCM', false, ['decrypt']);
  }
  /** Name, type and size of a shared file (decrypted here; the server cannot read them). */
  async function fileMeta(f, me, keys) { return JSON.parse(dec.decode(await open(await fileKey(f, me, keys), f.meta, 'prepdeck-meta'))); }
  /** Download and decrypt a shared file. The GCM tag proves nobody changed it on the way. */
  async function fileBlob(f, me, peer, keys) {
    var fk = await fileKey(f, me, keys), meta = await fileMeta(f, me, keys);
    var r = await fetch(PDBASE + 'api/community.php?a=file&id=' + f.id, { credentials: 'same-origin' });
    if (!r.ok) { var j = await r.json().catch(function () { return {}; }); throw new Error(j.error || 'Download failed.'); }
    var ct = await r.arrayBuffer(), from = f.from, to = from === me ? peer : me;
    var pt = await C.decrypt({ name: 'AES-GCM', iv: unb64(f.iv), additionalData: enc.encode('prepdeck-file|' + from + '|' + to) }, fk, ct)
      .catch(function () { throw new Error('This file could not be decrypted. It may have been changed or damaged.'); });
    return { blob: new Blob([pt], { type: meta.type }), name: meta.name, type: meta.type };
  }
  /** Safety code for two people: the same 30 digits on both screens means nobody swapped a key in between. */
  async function safetyCode(pubA, pubB) {
    var h = new Uint8Array(await C.digest('SHA-256', enc.encode([pubA, pubB].sort().join('|')))), d = '';
    for (var i = 0; i < 12 && d.length < 30; i++) d += ('00' + ((h[i * 2] << 8 | h[i * 2 + 1]) % 100000)).slice(-5);
    return d.slice(0, 30).match(/.{5}/g).join(' ');
  }
  window.E2EE = { supported: supported, setup: setup, rewrap: rewrap, fresh: newKeys, ready: ready, forget: forget, encryptFile: encryptFile, fileMeta: fileMeta, fileBlob: fileBlob, safetyCode: safetyCode, mem: function () { return mem; } };
})();
