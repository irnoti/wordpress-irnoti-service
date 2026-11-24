(function () {
  'use strict';
  var C = window.IRNOTI_SEO;
  if (!C) return;
  var $ = function (id) { return document.getElementById(id); };

  function post(action, data) {
    var fd = new FormData();
    fd.append('action', 'irnoti_seo_' + action); fd.append('nonce', C.nonce);
    Object.keys(data || {}).forEach(function (k) { if (data[k] != null) fd.append(k, data[k]); });
    return fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { success: false, data: { message: 'اتصال به سرور برقرار نشد.' } }; });
  }
  function msg(r) { return (r && r.data && r.data.message) || 'خطا'; }
  function el(tag, text, attrs) {
    var e = document.createElement(tag);
    if (text != null) e.textContent = text;
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    return e;
  }
  function btn(label, fn, cls) {
    var b = el('button', label, { type: 'button', 'class': 'button ' + (cls || 'button-small') });
    b.addEventListener('click', function (e) { e.preventDefault(); fn(b); });
    return b;
  }
  function toast(m, bad) {
    var t = el('div', m, { 'class': 'irn-toast' + (bad ? ' bad' : '') });
    document.body.appendChild(t); setTimeout(function () { t.remove(); }, 4500);
  }

  /* ---------------------------------------------------------------- editor access (block + classic) */
  var wpd = window.wp && wp.data;
  var gb = !!(wpd && wpd.select && wpd.select('core/editor') && wpd.select('core/editor').getEditedPostAttribute);
  var ed = {
    get: function (k) {
      if (gb) {
        var s = wpd.select('core/editor');
        if (k === 'content') return s.getEditedPostContent();
        if (k === 'slug') return s.getEditedPostAttribute('slug') || '';
        if (k === 'thumb') return s.getEditedPostAttribute('featured_media') ? '1' : '';
        return s.getEditedPostAttribute(k) || '';
      }
      if (k === 'title') return ($('title') || {}).value || '';
      if (k === 'slug') return ($('post_name') || {}).value || '';
      if (k === 'thumb') { var t = document.querySelector('#_thumbnail_id'); return t && t.value && t.value !== '-1' ? '1' : ''; }
      if (k === 'excerpt') return ($('excerpt') || {}).value || '';
      if (window.tinyMCE && tinyMCE.get('content') && !tinyMCE.get('content').isHidden()) return tinyMCE.get('content').getContent();
      return ($('content') || {}).value || '';
    },
    set: function (k, v) {
      if (gb) {
        var d = {}; d[k] = v; wpd.dispatch('core/editor').editPost(d); return;
      }
      var map = { title: 'title', excerpt: 'excerpt', content: 'content' }, node = $(map[k]);
      if (k === 'content' && window.tinyMCE && tinyMCE.get('content') && !tinyMCE.get('content').isHidden()) { tinyMCE.get('content').setContent(v); return; }
      if (node) { node.value = v; node.dispatchEvent(new Event('input', { bubbles: true })); }
    },
    content: function (html, replace) {
      if (gb && window.wp.blocks && wp.blocks.rawHandler) {
        var blocks = wp.blocks.rawHandler({ HTML: html });
        if (replace) wpd.dispatch('core/block-editor').resetBlocks(blocks); else wpd.dispatch('core/block-editor').insertBlocks(blocks);
        return;
      }
      if (replace) { ed.set('content', html); return; }
      ed.set('content', ed.get('content') + '\n' + html);
    },
    thumb: function (id) {
      if (gb) { wpd.dispatch('core/editor').editPost({ featured_media: id }); return; }
      var t = document.querySelector('#_thumbnail_id'); if (t) t.value = id;
    }
  };

  /* ---------------------------------------------------------------- editor box */
  var box = $('irnoti-seo-box');
  if (box) {
    var postId = box.getAttribute('data-post'), type = box.getAttribute('data-type'), aiOn = box.getAttribute('data-ai') === '1';
    var kwIn = $('irnoti-seo-kw'), descIn = $('irnoti-seo-desc'), out = $('irnoti-ai-out');
    var GRADE = { good: 'ok', mid: 'warn', low: 'bad' };

    var render = function (d) {
      var s = $('irnoti-seo-score'); s.textContent = '';
      s.className = 'irnseo-score ' + (GRADE[d.grade] || '');
      s.appendChild(el('b', String(d.score)));
      s.appendChild(el('small', d.grade === 'good' ? 'عالی' : d.grade === 'mid' ? 'قابل‌بهبود' : 'ضعیف'));
      var ul = $('irnoti-seo-checks'); ul.textContent = '';
      d.items.forEach(function (i) {
        var li = el('li', null, { 'class': 'is-' + i.state });
        li.appendChild(el('i', i.state === 'ok' ? '✓' : i.state === 'warn' ? '!' : '✕'));
        li.appendChild(el('b', i.label));
        li.appendChild(document.createTextNode(' — ' + i.detail));
        ul.appendChild(li);
      });
    };
    try { render(JSON.parse($('irnoti-seo-init').textContent)); } catch (e) { /* no initial data */ }

    var state = function (extra) {
      var d = {
        post_id: postId, type: type, title: ed.get('title'), content: ed.get('content'), slug: ed.get('slug'),
        has_thumb: ed.get('thumb') ? '1' : '', kw: kwIn.value, desc: descIn.value
      };
      Object.keys(extra || {}).forEach(function (k) { d[k] = extra[k]; });
      return d;
    };
    var recheck = function () {
      var b = $('irnoti-seo-recheck'); b.disabled = true;
      post('analyze', state()).then(function (r) { b.disabled = false; if (r.success) render(r.data); else toast(msg(r), true); });
    };
    $('irnoti-seo-recheck').addEventListener('click', recheck);
    var count = function () { $('irnoti-seo-desc-count').textContent = '(' + descIn.value.length + ')'; };
    descIn.addEventListener('input', count); count();

    // Apply into the editor. With Yoast / Rank Math the value is also written to their meta and their input is filled.
    var third = C.store !== 'own';
    var setMeta = function (which, value) {
      (which === 'kw' ? kwIn : descIn).value = value; count();
      if (third) {
        post('meta', { post_id: postId, which: which, value: value });
        var names = which === 'kw' ? ['yoast_wpseo_focuskw', 'yoast_wpseo_focuskw_text_input', 'rank_math_focus_keyword'] : ['yoast_wpseo_metadesc', 'rank_math_description'];
        names.forEach(function (n) {
          var f = document.querySelector('[name="' + n + '"],#' + n);
          if (f) { f.value = value; f.dispatchEvent(new Event('input', { bubbles: true })); f.dispatchEvent(new Event('change', { bubbles: true })); }
        });
      }
    };
    var applyTo = {
      title: function (v) { ed.set('title', v); },
      meta: function (v) { setMeta('desc', v); },
      keyword: function (v) { setMeta('kw', v); },
      excerpt: function (v) { ed.set('excerpt', v); }
    };
    var LINE_HELP = { title: 'عنوانی را انتخاب کنید تا جایگزین شود.', meta: 'یکی را انتخاب کنید تا در توضیحات متا قرار گیرد.', keyword: 'یکی را به‌عنوان کلمهٔ کلیدی اصلی انتخاب کنید.' };

    var showResult = function (task, d) {
      out.hidden = false; out.textContent = '';
      if (d.type === 'lines') {
        out.appendChild(el('p', LINE_HELP[task] || ''));
        d.lines.forEach(function (l) {
          var row = el('div', null, { 'class': 'irnseo-line' });
          row.appendChild(el('span', l, { dir: 'auto' }));
          row.appendChild(el('small', '(' + l.length + ')'));
          row.appendChild(btn('استفاده', function () { applyTo[task](l); recheck(); toast('اعمال شد.'); }));
          out.appendChild(row);
        });
      } else if (d.type === 'html') {
        var pv = el('div', null, { 'class': 'irnseo-preview' }); pv.innerHTML = d.text; // sanitized server-side with wp_kses_post
        out.appendChild(pv);
        var bar = el('p');
        bar.appendChild(btn('جایگزین کل متن', function () { if (confirm('متن فعلی با این نسخه جایگزین شود؟ (می‌توانید با Ctrl+Z برگردانید)')) { ed.content(d.text, true); recheck(); } }, 'button-primary'));
        bar.appendChild(document.createTextNode(' '));
        bar.appendChild(btn('افزودن به انتهای متن', function () { ed.content(d.text, false); recheck(); toast('اضافه شد.'); }));
        out.appendChild(bar);
      } else {
        out.appendChild(el('p', d.text, { dir: 'auto' }));
        if (applyTo[task]) out.appendChild(btn('استفاده', function () { applyTo[task](d.text); toast('اعمال شد.'); }, 'button-primary'));
      }
      out.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };

    var busy = function (b, on, label) { b.disabled = on; if (on) { b.setAttribute('data-l', b.textContent); b.textContent = label || 'در حال کار…'; } else { b.textContent = b.getAttribute('data-l') || b.textContent; } };

    var imageFlow = function (b, prompt) {
      if (!+postId) { toast('ابتدا نوشته را یک بار ذخیره کنید.', true); return; }
      busy(b, true, 'در حال ساخت تصویر (تا یک دقیقه)…');
      post('image', state({ prompt: prompt || '' })).then(function (r) {
        busy(b, false);
        if (!r.success) { toast(msg(r), true); return; }
        out.hidden = false; out.textContent = '';
        out.appendChild(el('img', null, { src: r.data.url, 'class': 'irnseo-img' }));
        var ta = el('textarea', r.data.prompt, { rows: 3, dir: 'ltr', 'class': 'irnseo-prompt' });
        out.appendChild(ta);
        var bar = el('p');
        bar.appendChild(btn('تنظیم به‌عنوان تصویر شاخص', function () { ed.thumb(r.data.id); toast('تصویر شاخص تنظیم شد؛ نوشته را به‌روزرسانی کنید.'); }, 'button-primary'));
        bar.appendChild(document.createTextNode(' '));
        bar.appendChild(btn('ساخت دوباره با این توضیح', function () { imageFlow(b, ta.value); }));
        out.appendChild(bar);
      });
    };

    if (aiOn) {
      Array.prototype.forEach.call(box.querySelectorAll('[data-ai]'), function (b) {
        if (b.tagName !== 'BUTTON') return;
        b.addEventListener('click', function () {
          var task = b.getAttribute('data-ai');
          if (task === 'image') { imageFlow(b, ''); return; }
          busy(b, true);
          post('action', state({ task: task })).then(function (r) {
            busy(b, false);
            if (r.success) showResult(task, r.data); else toast(msg(r), true);
          });
        });
      });
    }
  }

  /* ---------------------------------------------------------------- trends (tab + editor mini box) */
  var tr = $('irnoti-trends');
  if (tr && tr.getAttribute('data-enabled') === '1') {
    var mini = tr.getAttribute('data-mini') === '1';
    var drawTrends = function (items) {
      tr.textContent = '';
      if (!items.length) { tr.appendChild(el('p', 'ترندی دریافت نشد.', { 'class': 'irn-empty' })); return; }
      items.slice(0, mini ? 5 : 12).forEach(function (t) {
        var card = el('div', null, { 'class': 'irnseo-trend' });
        card.appendChild(el('strong', t.title, { dir: 'auto' }));
        if (t.summary && !mini) card.appendChild(el('p', t.summary));
        if (t.keywords && t.keywords.length) {
          var k = el('div', null, { 'class': 'irnseo-kws' });
          t.keywords.forEach(function (w) { k.appendChild(el('span', w)); });
          card.appendChild(k);
        }
        var bar = el('div', null, { 'class': 'irnseo-trend-bar' });
        bar.appendChild(btn('ساخت پیش‌نویس مقاله', function (b) {
          if (!confirm('یک پیش‌نویس با هوش مصنوعی ساخته می‌شود (از اعتبار IRNoti کم می‌شود). ادامه؟')) return;
          b.disabled = true; b.textContent = 'در حال نوشتن (تا یک دقیقه)…';
          post('draft', { topic: t.title, kw: (t.keywords && t.keywords[0]) || t.title }).then(function (r) {
            b.disabled = false; b.textContent = 'ساخت پیش‌نویس مقاله';
            if (!r.success) { toast(msg(r), true); return; }
            var a = el('a', 'ویرایش پیش‌نویس ←', { href: r.data.edit, target: '_blank', rel: 'noopener' });
            b.replaceWith(a);
          });
        }));
        if (mini && window.IRNOTI_SEO && $('irnoti-seo-kw')) {
          bar.appendChild(btn('کلمهٔ کلیدی', function () { $('irnoti-seo-kw').value = (t.keywords && t.keywords[0]) || t.title; toast('کلمهٔ کلیدی تنظیم شد.'); }));
        }
        card.appendChild(bar);
        tr.appendChild(card);
      });
    };
    var loadTrends = function (fresh) {
      tr.textContent = ''; tr.appendChild(el('p', 'در حال دریافت…', { 'class': 'irn-empty' }));
      post('trends', fresh ? { fresh: 1 } : {}).then(function (r) {
        if (r.success) drawTrends(r.data.items || []); else { tr.textContent = ''; tr.appendChild(el('p', msg(r), { 'class': 'irnseo-err' })); }
      });
    };
    loadTrends(false);
    var rf = $('irnoti-trends-refresh'); if (rf) rf.addEventListener('click', function () { loadTrends(true); });
  }

  /* ---------------------------------------------------------------- scan all content */
  var scan = $('irnoti-scan');
  if (scan) scan.addEventListener('click', function () {
    var st = $('irnoti-scan-status'), page = 1, done = 0;
    scan.disabled = true;
    (function next() {
      post('scan', { page: page }).then(function (r) {
        if (!r.success) { st.textContent = msg(r); scan.disabled = false; return; }
        done += r.data.count; st.textContent = done + ' / ' + r.data.total;
        if (r.data.done) { st.textContent = '✔ ' + done + ' — در حال بارگذاری…'; location.reload(); } else { page++; next(); }
      });
    })();
  });

  /* ---------------------------------------------------------------- learning report */
  var rep = $('irnoti-learn-report');
  if (rep) {
    var drawLearn = function (d) {
      rep.textContent = '';
      if (!d.time) { rep.appendChild(el('p', 'هنوز یادگیری انجام نشده است.', { 'class': 'irn-empty' })); return; }
      var h = el('div', null, { 'class': 'irnlearn-head ' + (d.ok ? 'ok' : 'bad') });
      h.appendChild(el('strong', d.ok ? 'آنچه از مکالمات یاد گرفتم' : 'یادگیری ناموفق بود'));
      h.appendChild(el('small', ' · ' + d.time + (d.ok ? ' · ' + d.processed + ' مکالمه بررسی شد' : '')));
      rep.appendChild(h);
      if (!d.ok) { rep.appendChild(el('p', d.error, { 'class': 'irnseo-err', dir: 'auto' })); return; }
      if (d.summary) rep.appendChild(el('p', d.summary, { dir: 'auto' }));
      if (!d.items.length) { rep.appendChild(el('p', 'این بار چیز تازه‌ای پیدا نشد.', { 'class': 'irn-empty' })); return; }
      var ul = el('ul', null, { 'class': 'irnlearn-list' });
      d.items.forEach(function (t) { ul.appendChild(el('li', t, { dir: 'auto' })); });
      rep.appendChild(ul);
    };
    var init = {}; try { init = JSON.parse(rep.getAttribute('data-init') || '{}'); } catch (e) { /* ignore */ }
    drawLearn(init);
    var run = $('irnoti-learn-run');
    if (run) run.addEventListener('click', function () {
      run.disabled = true; $('irnoti-learn-status').textContent = 'در حال یادگیری… (ممکن است چند دقیقه طول بکشد)';
      post('learn', {}).then(function (r) {
        run.disabled = false; $('irnoti-learn-status').textContent = '';
        if (r.success) drawLearn(r.data); else toast(msg(r), true);
      });
    });
  }
})();
