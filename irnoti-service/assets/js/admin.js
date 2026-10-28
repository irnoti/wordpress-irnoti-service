(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var fmt = new Intl.DateTimeFormat('fa-IR', { dateStyle: 'short', timeStyle: 'short' }); // Jalali display; API values stay Gregorian ISO.

  function post(action, nonce, data) {
    var fd = new FormData();
    fd.append('action', action); fd.append('nonce', nonce);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(IRNOTI.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  var voip = function (op, d) { d = d || {}; d.op = op; return post('irnoti_voip', IRNOTI.voipNonce, d); };
  var err = function (r) { return (r && r.data && r.data.message) || 'error'; };
  var rows = function (d) { return Array.isArray(d) ? d : ((d && (d.items || d.data || d.calls)) || []); };
  var date = function (v) { var t = Date.parse(v); return isNaN(t) ? '' : fmt.format(new Date(t)); };

  function el(tag, text, attrs) {
    var e = document.createElement(tag);
    if (text != null) e.textContent = text;
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    return e;
  }
  function btn(label, fn) { var b = el('button', label, { type: 'button', 'class': 'button button-small' }); b.addEventListener('click', function (e) { e.preventDefault(); fn(b); }); return b; }
  function table(cols, data, actions) {
    var t = el('table', null, { 'class': 'widefat striped' }), h = el('tr');
    cols.forEach(function (c) { h.appendChild(el('th', c.label)); });
    if (actions) h.appendChild(el('th', ''));
    t.appendChild(el('thead')).appendChild(h);
    var tb = t.appendChild(el('tbody'));
    data.forEach(function (row) {
      var tr = tb.appendChild(el('tr'));
      cols.forEach(function (c) {
        var v = row[c.key], td = tr.appendChild(el('td', null, c.ltr ? { dir: 'ltr' } : {})), txt = c.fmt ? c.fmt(v, row) : (v == null ? '' : String(v));
        if (c.badge && txt) td.appendChild(el('span', txt, { 'class': 'irn-badge ' + (BADGE[String(v)] || '') })); else td.textContent = txt;
      });
      if (actions) { var td = tr.appendChild(el('td')); actions(row).forEach(function (a) { td.appendChild(a); td.appendChild(document.createTextNode(' ')); }); }
    });
    if (!data.length) tb.appendChild(el('tr')).appendChild(el('td', '—', { colspan: cols.length + 1, 'class': 'irn-empty' }));
    return t;
  }
  function show(box, node) { box.textContent = ''; box.appendChild(node); }
  function toast(msg, bad) {
    var t = el('div', msg, { 'class': 'irn-toast' + (bad ? ' bad' : '') });
    document.body.appendChild(t); setTimeout(function () { t.remove(); }, 4000);
  }
  function ok(r) { if (!r.success) { toast(err(r), true); } return r.success; }
  var BADGE = { answered: 'ok', done: 'ok', completed: 'ok', missed: 'warn', ringing: 'brand', failed: 'bad', busy: 'warn', in: 'brand', out: 'brand' };

  // ---------- Woo sync ----------
  var syncBtn = $('irnoti-sync-all');
  if (syncBtn) {
    syncBtn.addEventListener('click', function () {
      var total = 0, page = 1, st = $('irnoti-sync-status'), ul = $('irnoti-sync-errors');
      syncBtn.disabled = true; ul.textContent = '';
      (function next() {
        post('irnoti_sync_batch', IRNOTI.syncNonce, { page: page }).then(function (r) {
          if (!r.success) { st.textContent = err(r); syncBtn.disabled = false; return; }
          total += r.data.count; st.textContent = total + ' …';
          r.data.errors.forEach(function (e) { ul.appendChild(el('li', e.title + ' — ' + e.error)); });
          if (r.data.done) { st.textContent = '✔ ' + total; syncBtn.disabled = false; } else { page++; next(); }
        }).catch(function () { st.textContent = 'network error'; syncBtn.disabled = false; });
      })();
    });
  }
  var pull = $('irnoti-pull-orders');
  if (pull) pull.addEventListener('click', function (e) {
    e.preventDefault(); var st = $('irnoti-pull-status'); st.textContent = '…';
    post('irnoti_pull_orders', IRNOTI.syncNonce, {}).then(function (r) { st.textContent = r.success ? '✔ +' + r.data.new : err(r); });
  });

  // ---------- VoIP ----------
  var root = $('irnoti-voip');
  if (!root) return;
  var panels = root.querySelectorAll('[data-panel]'), liveTimer = null;
  function open(sec) {
    panels.forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== sec; });
    root.querySelectorAll('[data-sec]').forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-sec') === sec); });
    clearInterval(liveTimer);
    if (sec === 'live') { loadLive(); liveTimer = setInterval(function () { if ($('irnoti-live-auto').checked) loadLive(); }, 5000); }
    if (sec === 'reports' && !$('irnoti-rep-table').firstChild) loadReport(true);
    if (sec === 'recordings' && !$('irnoti-rec').firstChild) loadRec();
    if (sec === 'blocklist') loadBlock();
    if (sec === 'accounts') loadAccounts();
  }
  root.querySelectorAll('[data-sec]').forEach(function (b) { b.addEventListener('click', function () { open(b.getAttribute('data-sec')); }); });
  open('phone');

  var ctc = $('irnoti-ctc');
  if (ctc) ctc.addEventListener('click', function (e) {
    e.preventDefault(); var st = $('irnoti-ctc-status'); st.textContent = '…';
    voip('call', { from: IRNOTI.voipFrom, to: $('irnoti-ctc-to').value }).then(function (r) { st.textContent = ''; toast(r.success ? 'تماس برقرار شد' : err(r), !r.success); });
  });

  // live calls
  function loadLive() {
    voip('live').then(function (r) {
      var box = $('irnoti-live');
      if (!r.success) { box.textContent = err(r); return; }
      show(box, table([
        { key: 'direction', label: 'جهت', badge: true }, { key: 'from', label: 'از', ltr: true }, { key: 'to', label: 'به', ltr: true },
        { key: 'state', label: 'وضعیت', badge: true }, { key: 'ageSec', label: 'ثانیه' },
      ], rows(r.data), function (c) {
        return [
          btn('قطع', function () { voip('hangup', { id: c.id }).then(function (x) { ok(x); loadLive(); }); }),
          btn('نگه‌دار', function () { voip('hold', { id: c.id, on: 1 }).then(ok); }),
          btn('انتقال', function () { var to = prompt('شمارهٔ مقصد انتقال'); if (to) voip('transfer', { id: c.id, to: to }).then(ok); }),
        ];
      }));
    });
  }
  $('irnoti-live-refresh').addEventListener('click', function (e) { e.preventDefault(); loadLive(); });

  // reports
  var iso = function (d) { return d.toISOString().slice(0, 10); };
  $('irnoti-rep-to').value = iso(new Date());
  $('irnoti-rep-from').value = iso(new Date(Date.now() - 7 * 864e5));
  var before = null, repTable = null;
  function repParams() {
    var p = { from: $('irnoti-rep-from').value, to: $('irnoti-rep-to').value, direction: $('irnoti-rep-dir').value, status: $('irnoti-rep-status').value, q: $('irnoti-rep-q').value };
    Object.keys(p).forEach(function (k) { if (!p[k]) delete p[k]; });
    return p;
  }
  function loadReport(reset) {
    var p = repParams();
    if (reset) {
      before = null; repTable = null; $('irnoti-rep-table').textContent = '…';
      voip('summary', { from: p.from, to: p.to }).then(function (r) {
        var box = $('irnoti-rep-summary'); box.textContent = '';
        if (!r.success) { box.textContent = err(r); return; }
        var ul = el('ul', null, { 'class': 'irnoti-cards' });
        Object.keys(r.data).forEach(function (k) { var v = r.data[k]; if (v === null || typeof v === 'object' || k === 'from' || k === 'to' || k === 'truncated') return; var li = ul.appendChild(el('li')); li.appendChild(el('b', typeof v === 'number' ? v.toLocaleString('fa-IR') : String(v))); li.appendChild(el('span', k)); });
        box.appendChild(ul);
      });
    }
    if (before) p.before = before;
    p.limit = 50;
    voip('calls', p).then(function (r) {
      if (!r.success) { $('irnoti-rep-table').textContent = err(r); return; }
      var items = rows(r.data); before = r.data && r.data.nextBefore || null;
      if (reset || !repTable) { repTable = table([
        { key: 'createdAt', label: 'زمان', fmt: date }, { key: 'direction', label: 'جهت', badge: true }, { key: 'fromNumber', label: 'از', ltr: true },
        { key: 'toNumber', label: 'به', ltr: true }, { key: 'status', label: 'وضعیت', badge: true }, { key: 'durationSec', label: 'مدت (ث)' },
        { key: 'price', label: 'هزینه (ریال)' },
      ], items); show($('irnoti-rep-table'), repTable); }
      else { var tb = repTable.tBodies[0]; items.forEach(function (row) { var tr = tb.appendChild(el('tr')); [date(row.createdAt), row.direction, row.fromNumber, row.toNumber, row.status, row.durationSec, row.price].forEach(function (v) { tr.appendChild(el('td', v == null ? '' : String(v))); }); }); }
      $('irnoti-rep-more').hidden = !before;
    });
  }
  $('irnoti-rep-go').addEventListener('click', function (e) { e.preventDefault(); loadReport(true); });
  $('irnoti-rep-more').addEventListener('click', function (e) { e.preventDefault(); loadReport(false); });
  $('irnoti-rep-csv').addEventListener('click', function (e) {
    e.preventDefault();
    var p = repParams(), q = Object.keys(p).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(p[k]); }).join('&');
    location.href = this.getAttribute('data-base') + '&' + q;
  });

  // recordings
  function loadRec() {
    var p = {}; if ($('irnoti-rec-kind').value) p.kind = $('irnoti-rec-kind').value;
    voip('recordings', p).then(function (r) {
      var box = $('irnoti-rec');
      if (!r.success) { box.textContent = err(r); return; }
      show(box, table([
        { key: 'createdAt', label: 'زمان', fmt: date }, { key: 'kind', label: 'نوع' }, { key: 'from', label: 'از', ltr: true }, { key: 'durationSec', label: 'مدت (ث)' },
        { key: 'transcript', label: 'متن', fmt: function (v) { return v ? String(v).slice(0, 120) : ''; } },
      ], rows(r.data), function (rec) {
        return [
          btn('پخش', function () {
            voip('rec_link', { id: rec.id }).then(function (x) {
              if (!ok(x) || !x.data.url) return;
              var a = $('irnoti-rec-audio'); a.hidden = false; a.src = x.data.url; a.play();
            });
          }),
          btn('رونویسی', function (b) { b.disabled = true; voip('rec_transcribe', { id: rec.id }).then(function (x) { ok(x); loadRec(); }); }),
          btn('حذف', function () { if (confirm('حذف شود؟')) voip('rec_delete', { id: rec.id }).then(function (x) { ok(x); loadRec(); }); }),
        ];
      }));
    });
  }
  $('irnoti-rec-go').addEventListener('click', function (e) { e.preventDefault(); loadRec(); });

  // block list
  function loadBlock() {
    voip('blocklist').then(function (r) {
      var box = $('irnoti-block');
      if (!r.success) { box.textContent = err(r); return; }
      show(box, table([{ key: 'number', label: 'شماره', ltr: true }, { key: 'reason', label: 'دلیل' }, { key: 'createdAt', label: 'زمان', fmt: date }], rows(r.data), function (b) {
        return [btn('رفع مسدودی', function () { voip('block_del', { id: b.id }).then(function (x) { ok(x); loadBlock(); }); })];
      }));
    });
  }
  $('irnoti-block-add').addEventListener('click', function (e) {
    e.preventDefault();
    voip('block_add', { number: $('irnoti-block-number').value, reason: $('irnoti-block-reason').value }).then(function (r) { if (ok(r)) { $('irnoti-block-number').value = ''; loadBlock(); } });
  });

  // SIP accounts + softphone
  var sel = $('irnoti-sip-acc');
  function loadAccounts() {
    voip('accounts').then(function (r) {
      var box = $('irnoti-accounts');
      if (!r.success) { box.textContent = err(r); return; }
      var list = rows(r.data);
      if (sel) { sel.textContent = ''; list.forEach(function (a) { var o = el('option', (a.label || a.username) + ' (' + a.username + ')'); o.value = a.id; sel.appendChild(o); }); }
      show(box, table([{ key: 'label', label: 'برچسب' }, { key: 'username', label: 'نام کاربری', ltr: true }, { key: 'active', label: 'فعال' }], list, function (a) {
        return [
          btn('چرخش رمز', function () { if (confirm('رمز عوض می‌شود و تلفن‌های متصل قطع می‌شوند.')) voip('account_rotate', { id: a.id }).then(ok); }),
          btn('حذف', function () { if (confirm('حذف شود؟')) voip('account_delete', { id: a.id }).then(function (x) { ok(x); loadAccounts(); }); }),
        ];
      }));
    });
  }
  loadAccounts();
  $('irnoti-sip-new').addEventListener('click', function (e) {
    e.preventDefault();
    voip('account_create', { label: 'WordPress' }).then(function (r) { if (ok(r)) loadAccounts(); });
  });

  if (!sel) return;
  var st = $('irnoti-sip-status'), ua = null, session = null, sipDomain = '';
  var btnCall = $('irnoti-sip-call'), btnHang = $('irnoti-sip-hangup');
  function attach(ev) { $('irnoti-sip-audio').srcObject = ev.streams[0]; }
  function end() { session = null; btnHang.disabled = true; }

  $('irnoti-sip-connect').addEventListener('click', function (e) {
    e.preventDefault();
    if (typeof JsSIP === 'undefined') { st.textContent = 'JsSIP not loaded'; return; }
    if (ua) { ua.stop(); ua = null; }
    st.textContent = '…';
    voip('account_credentials', { id: sel.value }).then(function (r) {
      if (!r.success) { st.textContent = err(r); return; }
      var c = r.data, ws = c.sipWsUrl || c.wsUrl || c.ws, domain = c.sipDomain || c.domain;
      if (!ws || !domain) { st.textContent = 'softphone unavailable'; return; }
      sipDomain = domain;
      ua = new JsSIP.UA({ sockets: [new JsSIP.WebSocketInterface(ws)], uri: 'sip:' + c.username + '@' + domain, password: c.secret, register: true });
      ua.on('registered', function () { st.textContent = '✔ registered'; btnCall.disabled = false; });
      ua.on('unregistered', function () { st.textContent = 'unregistered'; btnCall.disabled = true; });
      ua.on('registrationFailed', function (ev) { st.textContent = 'failed: ' + (ev.cause || ''); });
      ua.on('newRTCSession', function (ev) {
        session = ev.session;
        session.on('ended', end); session.on('failed', end);
        if (session.connection) session.connection.addEventListener('track', attach);
        session.on('peerconnection', function (pc) { pc.peerconnection.addEventListener('track', attach); });
        if (session.direction === 'incoming') {
          if (confirm('تماس ورودی از ' + (session.remote_identity.uri.user || '') + ' — پاسخ می‌دهید؟')) { session.answer({ mediaConstraints: { audio: true, video: false } }); btnHang.disabled = false; } else { session.terminate(); }
        }
      });
      ua.start();
    });
  });
  btnHang.addEventListener('click', function (e) { e.preventDefault(); if (session) session.terminate(); });
  btnCall.addEventListener('click', function (e) {
    e.preventDefault();
    var num = $('irnoti-sip-dial').value.replace(/\D/g, '');
    if (!ua || !num || !sipDomain) return;
    ua.call('sip:' + num + '@' + sipDomain, { mediaConstraints: { audio: true, video: false } });
    btnHang.disabled = false;
  });
})();
