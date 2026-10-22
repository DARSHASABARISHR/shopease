(function () {
  'use strict';
  const csrf = () => document.querySelector('meta[name="csrf"]')?.content || '';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  window.toast = function (msg, isErr) {
    let t = $('#toast');
    if (!t) { t = document.createElement('div'); t.id = 'toast'; document.body.appendChild(t); }
    t.textContent = msg; t.className = (isErr ? 'err ' : '') + 'show';
    clearTimeout(t._h); t._h = setTimeout(() => t.classList.remove('show'), 2600);
  };

  window.postJSON = async function (url, data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    const res = await fetch(url, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrf(), 'X-Requested-With': 'fetch' }, credentials: 'same-origin' });
    let j; try { j = await res.json(); } catch (e) { j = { ok: false, message: 'Unexpected server response.' }; }
    return j;
  };

  function setBadge(n) {
    const b = $('#cartBadge'); if (!b) return;
    b.textContent = n; b.classList.remove('bump'); void b.offsetWidth; b.classList.add('bump');
  }

  /* ---------- Add to cart (cards + detail page) ---------- */
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-add-cart]');
    if (!btn) return;
    e.preventDefault();
    const qtyEl = document.getElementById('detailQty');
    const qty = qtyEl ? parseInt(qtyEl.value, 10) || 1 : 1;
    btn.disabled = true;
    const j = await postJSON(BASE_URL + '/ajax_cart.php', { action: 'add', product_id: btn.dataset.addCart, qty });
    btn.disabled = false;
    if (j.ok) { setBadge(j.count); toast('Added to cart'); if (btn.dataset.buyNow) location.href = BASE_URL + '/cart.php'; }
    else toast(j.message || 'Could not add to cart', true);
  });

  /* ---------- Quantity steppers (detail page) ---------- */
  $$('.qty[data-detail]').forEach((box) => {
    const input = $('input', box), max = parseInt(input.max, 10) || 1;
    box.addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      let v = parseInt(input.value, 10) || 1;
      v += b.dataset.d === '+' ? 1 : -1;
      input.value = Math.max(1, Math.min(max, v));
    });
  });

  /* ---------- Cart page: update / remove via AJAX ---------- */
  const cartRoot = $('#cartRoot');
  if (cartRoot) {
    cartRoot.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-cart-act]'); if (!b) return;
      const row = b.closest('[data-pid]'); const pid = row.dataset.pid;
      const input = $('input.q', row);
      let j;
      if (b.dataset.cartAct === 'remove') j = await postJSON(BASE_URL + '/ajax_cart.php', { action: 'remove', product_id: pid });
      else {
        let v = parseInt(input.value, 10) || 1; v += b.dataset.cartAct === 'inc' ? 1 : -1;
        if (v < 1) return;
        j = await postJSON(BASE_URL + '/ajax_cart.php', { action: 'update', product_id: pid, qty: v });
      }
      applyCart(j);
    });
    cartRoot.addEventListener('change', async (e) => {
      if (!e.target.matches('input.q')) return;
      const pid = e.target.closest('[data-pid]').dataset.pid;
      applyCart(await postJSON(BASE_URL + '/ajax_cart.php', { action: 'update', product_id: pid, qty: parseInt(e.target.value, 10) || 1 }));
    });
  }
  function applyCart(j) {
    if (!j.ok) { toast(j.message || 'Cart error', true); if (j.reload) location.reload(); return; }
    setBadge(j.count);
    if (j.html) { $('#cartRoot').innerHTML = j.html; }
    if (j.message) toast(j.message);
  }

  /* ---------- Live search suggestions ---------- */
  const si = $('#searchInput'), box = $('#suggestBox');
  if (si && box) {
    let timer, ctl, idx = -1;
    const hide = () => { box.hidden = true; idx = -1; };
    si.addEventListener('input', () => {
      clearTimeout(timer);
      const q = si.value.trim();
      if (q.length < 2) return hide();
      timer = setTimeout(async () => {
        if (ctl) ctl.abort(); ctl = new AbortController();
        try {
          const r = await fetch(BASE_URL + '/search_suggest.php?q=' + encodeURIComponent(q), { signal: ctl.signal });
          const j = await r.json();
          if (!j.length) return hide();
          box.innerHTML = '';
          j.forEach((p) => {
            const a = document.createElement('a'); a.href = BASE_URL + '/product.php?id=' + p.id;
            const img = document.createElement('img'); img.src = p.image; img.alt = '';
            const s = document.createElement('span'); s.textContent = p.name;
            const pr = document.createElement('span'); pr.className = 'p'; pr.textContent = p.price;
            a.append(img, s, pr); box.appendChild(a);
          });
          box.hidden = false; idx = -1;
        } catch (e) { /* aborted */ }
      }, 220);
    });
    si.addEventListener('keydown', (e) => {
      const items = $$('a', box); if (box.hidden || !items.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault(); idx = (idx + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        items.forEach((a, i) => a.classList.toggle('sel', i === idx));
      } else if (e.key === 'Enter' && idx >= 0) { e.preventDefault(); location.href = items[idx].href; }
      else if (e.key === 'Escape') hide();
    });
    document.addEventListener('click', (e) => { if (!e.target.closest('.search')) hide(); });
  }

  /* ---------- Hero carousel ---------- */
  const car = $('.carousel');
  if (car) {
    const slides = $$('.slide', car), dots = $('.dots', car); let i = 0, t;
    slides.forEach((_, n) => { const d = document.createElement('i'); d.onclick = () => go(n, true); dots.appendChild(d); });
    function go(n, manual) {
      i = (n + slides.length) % slides.length;
      slides.forEach((s, k) => s.classList.toggle('active', k === i));
      $$('i', dots).forEach((d, k) => d.classList.toggle('on', k === i));
      if (manual) { clearInterval(t); t = setInterval(() => go(i + 1), 5000); }
    }
    $('.c-prev', car).onclick = () => go(i - 1, true);
    $('.c-next', car).onclick = () => go(i + 1, true);
    go(0); t = setInterval(() => go(i + 1), 5000);
  }

  /* ---------- Product gallery thumbnails ---------- */
  $$('.thumbs img').forEach((th) => th.addEventListener('click', () => {
    $('#mainImg').src = th.dataset.full;
    $$('.thumbs img').forEach((x) => x.classList.remove('on')); th.classList.add('on');
  }));

  /* ---------- Show / hide password (auto-applied to every password input) ---------- */
  const EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
  const EYE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20C5 20 1 12 1 12a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A9.1 9.1 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
  $$('input[type="password"]').forEach((inp) => {
    if (inp.dataset.eye) return; inp.dataset.eye = '1';
    const wrap = document.createElement('div'); wrap.className = 'pw-wrap';
    inp.parentNode.insertBefore(wrap, inp); wrap.appendChild(inp);
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'pw-eye'; b.innerHTML = EYE;
    b.setAttribute('aria-label', 'Show password'); b.setAttribute('aria-pressed', 'false');
    b.addEventListener('click', () => {
      const show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      b.innerHTML = show ? EYE_OFF : EYE;
      b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      b.setAttribute('aria-pressed', show ? 'true' : 'false');
      inp.focus({ preventScroll: true });
    });
    wrap.appendChild(b);
  });

  /* ---------- Real-time new / confirm password validation ---------- */
  $$('form[data-pw-form]').forEach((form) => {
    const nw = $('[data-pw-new]', form), cf = $('[data-pw-confirm]', form);
    if (!nw || !cf) return;
    const msg = $('.pw-msg', form), btn = $('[data-pw-submit]', form), strict = form.hasAttribute('data-pw-strict');
    const rules = { len: (v) => v.length >= 8, alpha: (v) => /[A-Za-z]/.test(v), num: (v) => /\d/.test(v) };
    function state() {
      const a = nw.value, b = cf.value;
      const rulesOk = Object.values(rules).every((f) => f(a));
      $$('[data-rule]', form).forEach((li) => li.classList.toggle('ok', rules[li.dataset.rule](a)));
      const mismatch = b !== '' && a !== b, match = b !== '' && a === b;
      cf.classList.toggle('invalid', mismatch);
      if (msg) { msg.className = 'pw-msg' + (mismatch ? ' err' : match ? ' ok' : ''); msg.textContent = mismatch ? 'Passwords do not match' : match ? 'Passwords match' : ''; }
      const ok = rulesOk && match;
      if (btn && strict) btn.disabled = !ok;
      return { ok, mismatch };
    }
    nw.addEventListener('input', state); cf.addEventListener('input', state);
    form.addEventListener('submit', (e) => {
      const s = state();
      if (!s.ok) {
        e.preventDefault();
        if (msg && !s.mismatch) { msg.className = 'pw-msg err'; msg.textContent = cf.value === '' ? 'Please confirm your password' : 'Password must be 8+ characters with letters and numbers'; }
        (s.mismatch ? cf : nw).focus();
      }
    });
    state();
  });

  /* ---------- OTP countdown + resend cooldown ---------- */
  $$('[data-countdown]').forEach((el) => {
    let s = parseInt(el.dataset.countdown, 10) || 0;
    const form = el.closest('form');
    (function tick() {
      if (s <= 0) { el.textContent = el.dataset.expiredText || '00:00'; el.classList.add('expired'); return; }
      el.textContent = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
      s--; setTimeout(tick, 1000);
    })();
  });
  $$('[data-resend-wait]').forEach((b) => {
    let s = parseInt(b.dataset.resendWait, 10) || 0; const label = b.dataset.label || b.textContent;
    if (s <= 0) { b.disabled = false; return; }
    b.disabled = true;
    (function tick() {
      if (s <= 0) { b.disabled = false; b.textContent = label; return; }
      b.textContent = label + ' (' + s + 's)'; s--; setTimeout(tick, 1000);
    })();
  });
})();
