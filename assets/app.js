(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var num = function (v) { return parseFloat(String(v).replace(/[\s ]/g, '').replace(',', '.')) || 0; };
  var fmt = function (n) { return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' '); };

  // header menu
  var mb = $('#menu-btn'), mp = $('#menu-pop');
  if (mb && mp) {
    mb.addEventListener('click', function (e) { e.stopPropagation(); mp.hidden = !mp.hidden; mb.setAttribute('aria-expanded', String(!mp.hidden)); });
    document.addEventListener('click', function (e) { if (!mp.contains(e.target)) { mp.hidden = true; } });
  }

  // contract edit: add/remove todos + live totals
  var todos = $('#todos');
  if (todos) {
    var recalc = function () {
      var p = 0, d = 0;
      $$('.todo-edit', todos).forEach(function (t) { p += num($('.t-price', t).value); d += parseInt($('.t-days', t).value, 10) || 0; });
      $('#sum-price').textContent = fmt(p); $('#sum-days').textContent = d;
    };
    var idx = $$('.todo-edit', todos).length;
    var add = $('#add-todo');
    if (add) {
      add.addEventListener('click', function () {
        var tpl = $('#todo-tpl').innerHTML.replace(/__i__/g, idx++);
        todos.insertAdjacentHTML('beforeend', tpl);
      });
    }
    todos.addEventListener('input', recalc);
    todos.addEventListener('click', function (e) {
      if (e.target.classList.contains('remove-todo')) {
        if ($$('.todo-edit', todos).length > 1) { e.target.closest('.todo-edit').remove(); recalc(); }
      }
    });
    var imgs = $('input[name="images[]"]');
    if (imgs) { imgs.addEventListener('change', function () { $('#img-count').textContent = imgs.files.length + ' selected'; }); }
  }

  // offer form: per-step offers drive the totals
  var op = $('#offer-price'), od = $('#offer-days');
  if (op && od) {
    var sync = function () {
      var P = $$('.o-price'), D = $$('.o-days'), any = false, sp = 0, sd = 0;
      P.forEach(function (el, i) { if (el.value.trim() !== '' || D[i].value.trim() !== '') { any = true; } sp += num(el.value); sd += parseInt(D[i].value, 10) || 0; });
      op.readOnly = od.readOnly = any;
      if (any) { op.value = sp || ''; od.value = sd || ''; }
    };
    $$('.o-price, .o-days').forEach(function (el) { el.addEventListener('input', sync); });
  }

  // profile: add custom field
  var addField = $('#add-field');
  if (addField) {
    addField.addEventListener('click', function () {
      var nm = $('#new-field-name'), label = nm.value.trim();
      if (!label) { return; }
      var box = $('#extra-fields'), i = box.children.length;
      var row = document.createElement('div'); row.className = 'field-row';
      var l = document.createElement('label'); l.appendChild(document.createTextNode(label));
      var h = document.createElement('input'); h.type = 'hidden'; h.name = 'extra[' + i + '][label]'; h.value = label;
      var v = document.createElement('input'); v.name = 'extra[' + i + '][value]'; v.maxLength = 190;
      l.appendChild(h); l.appendChild(v);
      var c = document.createElement('label'); c.className = 'check';
      var cb = document.createElement('input'); cb.type = 'checkbox'; cb.name = 'extra[' + i + '][public]'; cb.value = '1';
      c.appendChild(cb); c.appendChild(document.createTextNode(' Show in Public'));
      row.appendChild(l); row.appendChild(c); box.appendChild(row);
      nm.value = '';
    });
  }

  // stopwatch: tracked working time (d:h:m:s), starts from 00:00:00:00 on the first Start
  var sw = $('#stopwatch');
  if (sw) {
    var base = parseInt(sw.dataset.seconds, 10) || 0, t0 = Date.now();
    var two = function (n) { return (n < 10 ? '0' : '') + n; };
    var tick = function () {
      var s = base + (sw.dataset.running === '1' ? Math.floor((Date.now() - t0) / 1000) : 0);
      sw.textContent = two(Math.floor(s / 86400)) + ':' + two(Math.floor(s % 86400 / 3600)) + ':' + two(Math.floor(s % 3600 / 60)) + ':' + two(s % 60);
    };
    tick();
    setInterval(tick, 1000);
    var badge = $('#run-state');
    if (sw.dataset.poll) {
      var poll = function () {
        if (document.hidden) { return; }
        fetch(sw.dataset.poll, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
          if (!d) { return; }
          base = d.s; t0 = Date.now(); sw.dataset.running = d.running ? '1' : '0'; tick();
          if (badge) { badge.textContent = d.running ? 'Started' : 'Stopped'; badge.classList.toggle('on', d.running); }
        }).catch(function () {});
      };
      setInterval(poll, 8000);
    }
  }
})();

(function () {
  'use strict';
  // offerer's own extra To Dos (contract + offer pages)
  var box = document.getElementById('extra-todos'), addBtn = document.getElementById('add-extra'), tpl = document.getElementById('extra-tpl');
  if (box && addBtn && tpl) {
    var n = box.querySelectorAll('.extra-todo').length + 100;
    addBtn.addEventListener('click', function () {
      box.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/extra\[0\]/g, 'extra[' + (n++) + ']'));
      var last = box.lastElementChild; if (last) { var i = last.querySelector('input'); if (i) { i.focus(); } }
    });
    box.addEventListener('click', function (e) {
      if (e.target.classList.contains('remove-extra')) { e.target.closest('.extra-todo').remove(); box.dispatchEvent(new Event('input', { bubbles: true })); }
    });
    box.addEventListener('input', function () {
      var op = document.getElementById('offer-price'), od = document.getElementById('offer-days');
      if (!op || !od) { return; }
      var P = document.querySelectorAll('.o-price'), D = document.querySelectorAll('.o-days'), any = false, sp = 0, sd = 0;
      P.forEach(function (el, i) { if (el.value.trim() !== '' || D[i].value.trim() !== '') { any = true; } sp += parseFloat(String(el.value).replace(/\s/g, '').replace(',', '.')) || 0; sd += parseInt(D[i].value, 10) || 0; });
      op.readOnly = od.readOnly = any;
      if (any) { op.value = sp || ''; od.value = sd; }
    });
  }
})();

(function () {
  'use strict';

  // ---- Contract editor: thumbnails / file names of picked files before saving ----
  var form = document.getElementById('contract-form');
  if (form) {
    var canDT = typeof DataTransfer !== 'undefined';
    form.querySelectorAll('input[type=file]').forEach(function (inp) {
      var isImg = inp.name === 'images[]';
      var list = document.createElement('div');
      list.className = isImg ? 'picked-grid' : 'picked-files';
      inp.closest('.file-btn').insertAdjacentElement('afterend', list);
      var store = canDT ? new DataTransfer() : null;
      var render = function () {
        list.innerHTML = '';
        Array.prototype.forEach.call(inp.files, function (f, idx) {
          var item = document.createElement('div');
          item.className = isImg ? 'picked-img' : 'picked-file';
          if (isImg && f.type.indexOf('image/') === 0) {
            var im = document.createElement('img'); im.src = URL.createObjectURL(f); im.alt = ''; item.appendChild(im);
          }
          var nm = document.createElement('span'); nm.textContent = f.name + ' (' + Math.max(1, Math.round(f.size / 1024)) + ' KB)'; item.appendChild(nm);
          if (store) {
            var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'picked-x'; rm.textContent = '×'; rm.title = 'Remove';
            rm.addEventListener('click', function () {
              var next = new DataTransfer();
              Array.prototype.forEach.call(inp.files, function (g, j) { if (j !== idx) { next.items.add(g); } });
              store = next; inp.files = store.files; render();
            });
            item.appendChild(rm);
          }
          list.appendChild(item);
        });
        if (inp.files.length) {
          var hint = document.createElement('div'); hint.className = 'picked-hint'; hint.textContent = 'Not saved yet. Press Save Contract to upload.'; list.appendChild(hint);
        }
      };
      inp.addEventListener('change', function () {
        if (store) { // keep earlier picks and add the new ones
          var next = new DataTransfer();
          Array.prototype.forEach.call(store.files, function (g) { next.items.add(g); });
          Array.prototype.forEach.call(inp.files, function (g) { next.items.add(g); });
          store = next; inp.files = store.files;
        }
        render();
      });
    });
  }

  // ---- Lightbox for image galleries ----
  var box = null, imgs = [], cur = 0;
  function build() {
    box = document.createElement('div'); box.className = 'lb'; box.hidden = true;
    box.innerHTML = '<button type="button" class="lb-close" aria-label="Close">×</button><button type="button" class="lb-prev" aria-label="Previous">‹</button><img class="lb-img" alt=""><button type="button" class="lb-next" aria-label="Next">›</button><div class="lb-count"></div>';
    document.body.appendChild(box);
    box.addEventListener('click', function (e) {
      if (e.target.classList.contains('lb-prev')) { show(cur - 1); }
      else if (e.target.classList.contains('lb-next')) { show(cur + 1); }
      else if (!e.target.classList.contains('lb-img')) { close(); }
    });
    var x0 = null;
    box.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    box.addEventListener('touchend', function (e) { if (x0 === null) { return; } var d = e.changedTouches[0].clientX - x0; if (Math.abs(d) > 40) { show(cur + (d < 0 ? 1 : -1)); } x0 = null; });
  }
  function show(i) {
    cur = (i + imgs.length) % imgs.length;
    box.querySelector('.lb-img').src = imgs[cur];
    box.querySelector('.lb-count').textContent = (cur + 1) + ' / ' + imgs.length;
    var many = imgs.length > 1;
    box.querySelector('.lb-prev').style.display = box.querySelector('.lb-next').style.display = many ? '' : 'none';
  }
  function open(list, i) {
    if (!box) { build(); }
    imgs = list; box.hidden = false; document.body.classList.add('lb-open'); show(i);
  }
  function close() { box.hidden = true; document.body.classList.remove('lb-open'); box.querySelector('.lb-img').removeAttribute('src'); }
  document.addEventListener('click', function (e) {
    var im = e.target.closest ? e.target.closest('.gallery img') : null;
    if (!im) { return; }
    e.preventDefault();
    var g = im.closest('.gallery'), all = Array.prototype.slice.call(g.querySelectorAll('img'));
    open(all.map(function (x) { var a = x.closest('a'); return a ? a.getAttribute('href') : x.getAttribute('src'); }), all.indexOf(im));
  });
  document.addEventListener('keydown', function (e) {
    if (!box || box.hidden) { return; }
    if (e.key === 'Escape') { close(); } else if (e.key === 'ArrowLeft') { show(cur - 1); } else if (e.key === 'ArrowRight') { show(cur + 1); }
  });
})();

(function () {
  'use strict';

  // ---- "Back" link: return to the previous page, fall back to the contracts list ----
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[data-back]') : null;
    if (!a) { return; }
    var ref = document.referrer || '';
    if (history.length > 1 && ref.indexOf(location.origin) === 0) { e.preventDefault(); history.back(); }
  });

  // ---- Profile pop-up: opens over the current page instead of navigating away ----
  var dlg = null, body = null, currentUrl = '';
  function closeDlg() { if (dlg) { dlg.hidden = true; body.innerHTML = ''; document.body.classList.remove('lb-open'); } }
  function load(url, fallbackNav) {
    if (!dlg) {
      dlg = document.createElement('div'); dlg.className = 'modal'; dlg.hidden = true;
      dlg.innerHTML = '<div class="modal-card" role="dialog" aria-modal="true"><button type="button" class="modal-x" aria-label="Close">×</button><div class="modal-body"></div></div>';
      document.body.appendChild(dlg);
      body = dlg.querySelector('.modal-body');
      dlg.addEventListener('click', function (e) { if (e.target === dlg || e.target.classList.contains('modal-x')) { closeDlg(); } });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !dlg.hidden) { closeDlg(); } });
      body.addEventListener('submit', function (e) {
        var f = e.target; if (!f.action) { return; }
        e.preventDefault();
        fetch(f.action, { method: 'POST', body: new FormData(f), credentials: 'same-origin' }).then(function () { load(currentUrl); });
      });
    }
    currentUrl = url;
    return fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + 'modal=1', { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) { throw new Error('http ' + r.status); }
      return r.text();
    }).then(function (html) {
      body.innerHTML = html; dlg.hidden = false; document.body.classList.add('lb-open');
    }).catch(function () { if (fallbackNav) { location.href = url; } });
  }
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || a.target === '_blank' || a.hasAttribute('data-nomodal')) { return; }
    if (a.hasAttribute('data-modal')) { e.preventDefault(); load(a.getAttribute('href'), true); return; }
    var m = /^\/user\/(\d+)$/.exec(a.getAttribute('href'));
    if (!m || a.closest('.modal')) { return; }
    e.preventDefault();
    load('/user/' + m[1], true);
  });
})();
