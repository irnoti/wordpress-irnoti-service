(function () {
  'use strict';
  var D = window.IRNOTI_SMS, R = window.IRNOTI_RULE || { params: {}, pattern: '' };
  var form = document.getElementById('irnoti-rule-form');
  if (!D || !form) return;
  var $ = function (id) { return document.getElementById(id); };
  var evSel = $('irnoti-r-event'), recSel = $('irnoti-r-recipient'), custom = $('irnoti-r-custom'), text = $('irnoti-r-text');
  var patSel = $('irnoti-r-pattern'), paramsBox = $('irnoti-r-params'), preview = $('irnoti-r-preview'), count = $('irnoti-r-count');
  var last = text;

  function el(tag, txt, attrs) { var e = document.createElement(tag); if (txt != null) e.textContent = txt; Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); }); return e; }
  function type() { return (D.events[evSel.value] || {}).type || 'order'; }
  function mode() { return form.querySelector('input[name=mode]:checked').value; }
  function render(tpl) { var s = D.samples[type()] || {}; return String(tpl || '').replace(/\{(\w+)\}/g, function (_, k) { return s[k] != null ? s[k] : ''; }).trim(); }
  function segments(t) {
    var n = t.length; if (!n) return 0;
    var ascii = !/[^\x00-\x7F]/.test(t);
    return n <= (ascii ? 160 : 70) ? 1 : Math.ceil(n / (ascii ? 153 : 67));
  }

  // insert {token} at the caret of the last focused field
  function insert(tok) {
    var f = last || text, s = f.selectionStart || 0, e = f.selectionEnd || 0;
    f.value = f.value.slice(0, s) + tok + f.value.slice(e);
    f.focus(); f.selectionStart = f.selectionEnd = s + tok.length; update();
  }
  function chips(box) {
    box.textContent = '';
    var v = D.vars[type()] || {};
    Object.keys(v).forEach(function (k) {
      var b = el('button', v[k], { type: 'button', 'class': 'irnoti-chip', title: '{' + k + '}' });
      b.addEventListener('click', function () { insert('{' + k + '}'); });
      box.appendChild(b);
    });
  }

  var SYN = { name: 'first_name', fname: 'first_name', family: 'last_name', lname: 'last_name', order: 'order_id', orderid: 'order_id', price: 'total', amount: 'total', link: 'order_url', url: 'order_url', product: 'product_name', phone: 'phone', mobile: 'phone', date: 'date', status: 'status', shop: 'site', store: 'site' };
  function guess(name) {
    var v = D.vars[type()] || {}, k = name.toLowerCase();
    if (v[k]) return '{' + k + '}';
    return SYN[k] && v[SYN[k]] ? '{' + SYN[k] + '}' : '';
  }

  function buildParams() {
    var code = patSel.value, p = D.patterns.filter(function (x) { return x.code === code; })[0];
    var old = {}; paramsBox.querySelectorAll('input').forEach(function (i) { old[i.getAttribute('data-p')] = i.value; });
    paramsBox.textContent = '';
    if (!p) return;
    paramsBox.appendChild(el('p', p.body, { 'class': 'irnoti-patbody' }));
    var seen = {}, re = /\{(\w+)\}/g, m;
    while ((m = re.exec(p.body))) {
      if (seen[m[1]]) continue; seen[m[1]] = 1;
      var row = el('p'), inp = el('input', null, { type: 'text', name: 'params[' + m[1] + ']', 'data-p': m[1], placeholder: '{…}' });
      var preset = old[m[1]] != null ? old[m[1]] : (R.pattern === code && R.params[m[1]] != null ? R.params[m[1]] : guess(m[1]));
      inp.value = preset;
      inp.addEventListener('focus', function () { last = this; }); inp.addEventListener('input', update);
      row.appendChild(el('code', '{' + m[1] + '}')); row.appendChild(document.createTextNode(' ← ')); row.appendChild(inp);
      paramsBox.appendChild(row);
    }
    chips(paramsBox.appendChild(el('div', null, { 'class': 'irnoti-chips' })));
  }

  function update() {
    var isP = mode() === 'pattern', out = '';
    form.querySelectorAll('[data-for]').forEach(function (r) { r.hidden = r.getAttribute('data-for') !== mode(); });
    form.querySelectorAll('[data-only]').forEach(function (r) { r.hidden = r.getAttribute('data-only') !== type(); });
    custom.hidden = recSel.value !== 'custom';
    if (isP) {
      var code = patSel.value, p = D.patterns.filter(function (x) { return x.code === code; })[0];
      if (p) { var vals = {}; paramsBox.querySelectorAll('input').forEach(function (i) { vals[i.getAttribute('data-p')] = render(i.value); }); out = p.body.replace(/\{(\w+)\}/g, function (_, k) { return vals[k] != null ? vals[k] : ''; }); }
    } else { out = render(text.value); count.textContent = text.value.length + ' کاراکتر · ' + segments(out) + ' پارت'; }
    preview.textContent = out || '—';
  }

  D.patterns.forEach(function (p) { var o = el('option', p.name + ' (' + p.code + ')'); o.value = p.code; if (p.code === R.pattern) o.selected = true; patSel.appendChild(o); });
  text.addEventListener('focus', function () { last = text; }); text.addEventListener('input', update);
  chips(form.querySelector('.irnoti-chips[data-target]'));
  evSel.addEventListener('change', function () { chips(form.querySelector('.irnoti-chips[data-target]')); buildParams(); update(); });
  recSel.addEventListener('change', update);
  patSel.addEventListener('change', function () { buildParams(); update(); });
  form.querySelectorAll('input[name=mode]').forEach(function (r) { r.addEventListener('change', update); });
  buildParams(); update();
})();
