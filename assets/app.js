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

  // stopwatch: time left until the agreed deadline (d:h:m:s)
  var sw = $('#stopwatch');
  if (sw) {
    var p = sw.dataset.until.split('-'), end = new Date(+p[0], +p[1] - 1, +p[2], 23, 59, 59).getTime();
    var two = function (n) { return (n < 10 ? '0' : '') + n; };
    var tick = function () {
      var s = Math.floor((end - Date.now()) / 1000), neg = s < 0; s = Math.abs(s);
      sw.textContent = (neg ? '-' : '') + two(Math.floor(s / 86400)) + ':' + two(Math.floor(s % 86400 / 3600)) + ':' + two(Math.floor(s % 3600 / 60)) + ':' + two(s % 60);
    };
    tick();
    if (sw.dataset.paused !== '1') { setInterval(tick, 1000); }
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
  // show picked file names in the contract editor (files are sent with "Save Contract")
  document.querySelectorAll('.file-btn input[type=file]').forEach(function (inp) {
    if (inp.form && inp.form.id !== 'contract-form') { return; }
    var info = document.createElement('div'); info.className = 'picked';
    inp.closest('.file-btn').insertAdjacentElement('afterend', info);
    inp.addEventListener('change', function () {
      info.textContent = Array.prototype.map.call(inp.files, function (f) { return f.name; }).join(', ') + (inp.files.length ? ' - will be uploaded when you press Save Contract' : '');
    });
  });
})();
