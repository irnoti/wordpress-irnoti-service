(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };

  function post(action, nonceKey, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', nonceKey);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(IRNOTI.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  var voip = function (a, d) { return post('irnoti_voip_' + a, IRNOTI.voipNonce, d); };
  var err = function (r) { return (r && r.data && r.data.message) || 'error'; };

  // ---- product sync ----
  var syncBtn = $('irnoti-sync-all');
  if (syncBtn) {
    syncBtn.addEventListener('click', function () {
      var total = 0, page = 1, st = $('irnoti-sync-status'), ul = $('irnoti-sync-errors');
      syncBtn.disabled = true; ul.textContent = '';
      (function next() {
        post('irnoti_sync_batch', IRNOTI.syncNonce, { page: page }).then(function (r) {
          if (!r.success) { st.textContent = err(r); syncBtn.disabled = false; return; }
          total += r.data.count;
          st.textContent = total + ' …';
          r.data.errors.forEach(function (e) { var li = document.createElement('li'); li.textContent = e.title + ' — ' + e.error; ul.appendChild(li); });
          if (r.data.done) { st.textContent = '✔ ' + total; syncBtn.disabled = false; } else { page++; next(); }
        }).catch(function () { st.textContent = 'network error'; syncBtn.disabled = false; });
      })();
    });
  }

  // ---- click to call ----
  var ctc = $('irnoti-ctc');
  if (ctc) {
    ctc.addEventListener('click', function (e) {
      e.preventDefault();
      var st = $('irnoti-ctc-status'); st.textContent = '…';
      voip('call', { from: IRNOTI.voipFrom, to: $('irnoti-ctc-to').value }).then(function (r) { st.textContent = r.success ? '✔' : err(r); });
    });
  }

  // ---- softphone ----
  var sel = $('irnoti-sip-acc');
  if (!sel) return;
  var st = $('irnoti-sip-status'), ua = null, session = null, sipDomain = '';
  var btnCall = $('irnoti-sip-call'), btnHang = $('irnoti-sip-hangup');

  function loadAccounts() {
    voip('accounts').then(function (r) {
      if (!r.success) { st.textContent = err(r); return; }
      var list = Array.isArray(r.data) ? r.data : (r.data.items || []);
      sel.textContent = '';
      list.forEach(function (a) { var o = document.createElement('option'); o.value = a.id; o.textContent = (a.label || a.username) + ' (' + a.username + ')'; sel.appendChild(o); });
    });
  }
  loadAccounts();

  $('irnoti-sip-new').addEventListener('click', function (e) {
    e.preventDefault();
    voip('create_account', { label: 'WordPress' }).then(function (r) { r.success ? loadAccounts() : (st.textContent = err(r)); });
  });

  $('irnoti-sip-connect').addEventListener('click', function (e) {
    e.preventDefault();
    if (typeof JsSIP === 'undefined') { st.textContent = 'JsSIP not loaded'; return; }
    if (ua) { ua.stop(); ua = null; }
    st.textContent = '…';
    voip('credentials', { id: sel.value }).then(function (r) {
      if (!r.success) { st.textContent = err(r); return; }
      var c = r.data, ws = c.sipWsUrl || c.wsUrl || c.ws, domain = c.sipDomain || c.domain;
      sipDomain = domain;
      if (!ws || !domain) { st.textContent = 'softphone unavailable'; return; }
      ua = new JsSIP.UA({
        sockets: [new JsSIP.WebSocketInterface(ws)],
        uri: 'sip:' + c.username + '@' + domain,
        password: c.secret,
        register: true
      });
      ua.on('registered', function () { st.textContent = '✔ registered'; btnCall.disabled = false; });
      ua.on('unregistered', function () { st.textContent = 'unregistered'; btnCall.disabled = true; });
      ua.on('registrationFailed', function (ev) { st.textContent = 'failed: ' + (ev.cause || ''); });
      ua.on('newRTCSession', function (ev) {
        session = ev.session;
        session.on('ended', end); session.on('failed', end);
        session.connection && session.connection.addEventListener('track', attach);
        if (session.direction === 'incoming') {
          if (confirm('تماس ورودی از ' + (session.remote_identity.uri.user || '') + ' — پاسخ می‌دهید؟')) { session.answer({ mediaConstraints: { audio: true, video: false } }); btnHang.disabled = false; } else { session.terminate(); }
        }
      });
      ua.start();
    });
  });

  function attach(ev) { var a = $('irnoti-sip-audio'); a.srcObject = ev.streams[0]; }
  function end() { session = null; btnHang.disabled = true; }

  btnHang.addEventListener('click', function (e) { e.preventDefault(); if (session) session.terminate(); });
  btnCall.addEventListener('click', function (e) {
    e.preventDefault();
    if (!ua) return;
    var num = $('irnoti-sip-dial').value.replace(/\D/g, '');
    if (!num || !sipDomain) return;
    ua.call('sip:' + num + '@' + sipDomain, { mediaConstraints: { audio: true, video: false } });
    btnHang.disabled = false;
  });
})();
