/* "Post to my wall" in the VK Mini App: draws the contract card (like the mobile card of the site) on a canvas,
 * uploads it as a wall photo through our /vk/upload proxy and opens the VK wall post box with the photo and the link.
 * Any failure (no permission, no network) falls back to a plain link post. Data comes in window.BBL_CARD. */
(function () {
  'use strict';
  var D = window.BBL_CARD, btn = document.getElementById('wall-post');
  if (!D || !btn) { return; }
  var FONT = '"Fira Sans", system-ui, sans-serif', W = 540, PAD = 28, SCALE = 2;
  var GREEN = '#12ad00', INK = '#333', GREY = '#8a8a8a', LINE = '#d9d9d9';

  function loadImage(src) {
    return new Promise(function (ok) {
      if (!src) { return ok(null); }
      var i = new Image();
      i.onload = function () { ok(i); };
      i.onerror = function () { ok(null); };
      i.src = src;
    });
  }

  function rr(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }

  /** Greedy word wrap; the last allowed line ends with an ellipsis. */
  function wrap(ctx, text, maxW, maxLines) {
    var words = String(text).replace(/\s+/g, ' ').trim().split(' '), lines = [], cur = '', i;
    for (i = 0; i < words.length; i++) {
      var t = cur ? cur + ' ' + words[i] : words[i];
      if (ctx.measureText(t).width > maxW && cur) { lines.push(cur); cur = words[i]; } else { cur = t; }
    }
    if (cur) { lines.push(cur); }
    if (lines.length > maxLines) {
      lines = lines.slice(0, maxLines);
      var last = lines[maxLines - 1];
      while (last.length > 1 && ctx.measureText(last + '…').width > maxW) { last = last.slice(0, -1); }
      lines[maxLines - 1] = last.replace(/[\s.,;:-]+$/, '') + '…';
    }
    return lines;
  }

  function fit(ctx, text, maxW) {
    var t = String(text);
    if (ctx.measureText(t).width <= maxW) { return t; }
    while (t.length > 1 && ctx.measureText(t + '…').width > maxW) { t = t.slice(0, -1); }
    return t + '…';
  }

  async function drawCard() {
    try {
      await Promise.all([document.fonts.load('700 34px "Fira Sans"'), document.fonts.load('400 16px "Fira Sans"'), document.fonts.load('500 16px "Fira Sans"')]);
    } catch (e) { /* the system font is used */ }
    var img = await loadImage(D.image);
    var cv = document.createElement('canvas');
    cv.width = W * SCALE; cv.height = 1400 * SCALE;
    var ctx = cv.getContext('2d');
    ctx.scale(SCALE, SCALE);
    ctx.textBaseline = 'alphabetic';

    // picture panel
    var top = 230;
    ctx.fillStyle = '#e3f4fd'; ctx.fillRect(0, 0, W, top);
    if (img) {
      var s = Math.min((W - 80) / img.width, (top - 40) / img.height);
      if (D.cover) { s = Math.max(W / img.width, top / img.height); }
      var iw = img.width * s, ih = img.height * s;
      ctx.save(); ctx.beginPath(); ctx.rect(0, 0, W, top); ctx.clip();
      ctx.drawImage(img, (W - iw) / 2, (top - ih) / 2, iw, ih);
      ctx.restore();
    }
    var y = top + 52;

    // title and date
    ctx.fillStyle = INK; ctx.font = '700 31px ' + FONT;
    ctx.font = '400 17px ' + FONT;
    var dateW = ctx.measureText(D.date).width;
    ctx.font = '700 31px ' + FONT;
    var tl = wrap(ctx, D.title, W - PAD * 2 - dateW - 14, 2);
    tl.forEach(function (ln, i) { ctx.fillText(ln, PAD, y + i * 38); });
    y += (tl.length - 1) * 38;
    ctx.fillStyle = GREY; ctx.font = '400 17px ' + FONT; ctx.textAlign = 'right';
    ctx.fillText(D.date, W - PAD, y); ctx.textAlign = 'left';

    // author with stars, category chip
    y += 30;
    ctx.fillStyle = GREEN; ctx.font = '500 15px ' + FONT;
    var an = D.author, stars = '';
    for (var k = 1; k <= 5; k++) { stars += k <= Math.round(D.rating) ? '★' : '☆'; }
    ctx.fillText(an, PAD, y);
    var ax = PAD + ctx.measureText(an + ' ').width;
    ctx.font = '400 13px ' + FONT; ctx.fillText(stars + ' (' + D.ratingCount + ')', ax, y);
    if (D.category) {
      ctx.font = '500 15px ' + FONT;
      var cw = ctx.measureText(D.category).width + 34;
      cw = Math.min(cw, 220);
      rr(ctx, W - PAD - cw, y - 18, cw, 32, 16); ctx.fillStyle = '#d3dcf7'; ctx.fill();
      ctx.fillStyle = '#2c3e7a'; ctx.textAlign = 'center';
      ctx.fillText(fit(ctx, D.category, cw - 24), W - PAD - cw / 2, y + 4); ctx.textAlign = 'left';
    }
    y += 22;
    ctx.fillStyle = GREY; ctx.font = '400 14px ' + FONT;
    ctx.fillText(fit(ctx, D.location, W - PAD * 2 - 200), PAD, y);

    // description
    y += 34;
    ctx.fillStyle = INK; ctx.font = '400 16px ' + FONT;
    wrap(ctx, D.description, W - PAD * 2, 5).forEach(function (ln) { ctx.fillText(ln, PAD, y); y += 24; });

    // steps and price
    y += 6; ctx.fillStyle = LINE; ctx.fillRect(PAD, y, W - PAD * 2, 1);
    y += 62;
    ctx.fillStyle = GREEN; ctx.font = '700 48px ' + FONT; ctx.fillText(String(D.steps), PAD, y);
    ctx.textAlign = 'right'; ctx.fillText(D.price, W - PAD, y);
    ctx.textAlign = 'left'; ctx.fillStyle = INK; ctx.font = '400 20px ' + FONT; ctx.fillText(D.stepsLabel, PAD, y + 28);
    ctx.textAlign = 'right'; ctx.fillStyle = GREY; ctx.font = '400 16px ' + FONT; ctx.fillText(D.currency, W - PAD, y + 26);
    ctx.textAlign = 'left';
    y += 50; ctx.fillStyle = LINE; ctx.fillRect(PAD, y, W - PAD * 2, 1);

    // tasks
    y += 10;
    var shown = D.todos.slice(0, 6);
    shown.forEach(function (t, i) {
      var rowTop = y;
      if (i % 2 === 0) { ctx.fillStyle = '#f5f5f5'; ctx.fillRect(PAD, rowTop, W - PAD * 2, 40); }
      ctx.fillStyle = INK; ctx.font = '500 19px ' + FONT;
      var pr = D.symbol + ' ' + t.price;
      ctx.font = '700 20px ' + FONT; var pw = ctx.measureText(pr).width;
      ctx.fillStyle = GREEN; ctx.textAlign = 'right'; ctx.fillText(pr, W - PAD - 10, rowTop + 28); ctx.textAlign = 'left';
      ctx.fillStyle = INK; ctx.font = '500 19px ' + FONT;
      ctx.fillText(fit(ctx, t.name, W - PAD * 2 - pw - 40), PAD + 10, rowTop + 28);
      y += 40;
    });
    if (D.todos.length > shown.length) {
      ctx.fillStyle = GREY; ctx.font = '400 15px ' + FONT;
      ctx.fillText('+ ' + (D.todos.length - shown.length) + ' …', PAD + 10, y + 24); y += 34;
    }
    y += 16;
    ctx.fillStyle = GREEN; ctx.font = '700 15px ' + FONT; ctx.fillText('bablito', PAD, y + 10);
    ctx.fillStyle = GREY; ctx.font = '400 13px ' + FONT; ctx.textAlign = 'right'; ctx.fillText('bablito.ru', W - PAD, y + 10); ctx.textAlign = 'left';
    var H = y + 34;

    // crop and round the corners
    var out = document.createElement('canvas');
    out.width = W * SCALE; out.height = Math.round(H * SCALE);
    var o = out.getContext('2d');
    o.save(); rr(o, 0, 0, out.width, out.height, 22 * SCALE); o.clip();
    o.fillStyle = '#ffffff'; o.fillRect(0, 0, out.width, out.height);
    o.drawImage(cv, 0, 0, out.width, out.height, 0, 0, out.width, out.height);
    o.restore();
    o.strokeStyle = '#dedede'; o.lineWidth = 2; rr(o, 1, 1, out.width - 2, out.height - 2, 22 * SCALE); o.stroke();
    return new Promise(function (ok) { out.toBlob(ok, 'image/png'); });
  }

  window.bblDrawCard = drawCard; // used by the preview test
  function fallback() { window.open(D.fallback, '_blank'); }

  async function post() {
    var v = '5.199';
    try {
      var tok = await vkBridge.send('VKWebAppGetAuthToken', { app_id: D.appId, scope: 'photos' });
      var up = await vkBridge.send('VKWebAppCallAPIMethod', { method: 'photos.getWallUploadServer', params: { v: v, access_token: tok.access_token } });
      var blob = await drawCard();
      var f = new FormData();
      f.append('_csrf', D.csrf); f.append('upload_url', up.response.upload_url); f.append('photo', blob, 'contract.png');
      var r = await fetch(D.proxy, { method: 'POST', body: f, credentials: 'include' }).then(function (x) { return x.json(); });
      if (!r || !r.photo) { throw new Error('upload'); }
      var sv = await vkBridge.send('VKWebAppCallAPIMethod', { method: 'photos.saveWallPhoto', params: { v: v, access_token: tok.access_token, server: r.server, photo: r.photo, hash: r.hash } });
      var ph = sv.response[0];
      await vkBridge.send('VKWebAppShowWallPostBox', { message: D.text, attachments: 'photo' + ph.owner_id + '_' + ph.id + ',' + D.url });
    } catch (e) {
      if (e && e.error_type === 'client_error' && e.error_data && e.error_data.error_code === 4) { return; } // the person closed the dialog
      try { await vkBridge.send('VKWebAppShowWallPostBox', { message: D.text, attachments: D.url }); } catch (e2) { fallback(); }
    }
  }

  btn.addEventListener('click', function () {
    if (!window.vkBridge) { return fallback(); }
    btn.disabled = true;
    post().then(function () { btn.disabled = false; }, function () { btn.disabled = false; });
  });
})();
