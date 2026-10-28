(function () {
  'use strict';
  var send = document.getElementById('irnoti-otp-send'), verify = document.getElementById('irnoti-otp-verify'), msg = document.getElementById('irnoti-otp-msg');
  if (!send) return;
  var mobile = '';
  function call(action, data) {
    var fd = new FormData();
    fd.append('action', action); fd.append('nonce', IRNOTI_OTP.nonce);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(IRNOTI_OTP.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  send.addEventListener('submit', function (e) {
    e.preventDefault(); mobile = send.mobile.value;
    call('irnoti_otp_send', { mobile: mobile }).then(function (r) {
      msg.textContent = r.data && r.data.message || '';
      if (r.success) { verify.hidden = false; verify.code.focus(); }
    });
  });
  verify.addEventListener('submit', function (e) {
    e.preventDefault();
    call('irnoti_otp_verify', { mobile: mobile, code: verify.code.value, redirect: IRNOTI_OTP.redirect }).then(function (r) {
      if (r.success) { location.href = r.data.redirect; } else { msg.textContent = r.data && r.data.message || ''; }
    });
  });
})();
