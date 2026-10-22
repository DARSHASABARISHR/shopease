/* Customer return flow: reason -> email OTP -> verify (native <dialog>, Fetch API) */
(function () {
  'use strict';
  const dlg = document.getElementById('returnDialog'); if (!dlg) return;
  const $ = (s) => dlg.querySelector(s);
  const st = { order: 0, product: 0, rid: 0, name: '', t: null };
  const step = (n) => dlg.querySelectorAll('[data-step]').forEach((x) => (x.hidden = x.dataset.step !== String(n)));
  const msg = (t) => { const m = $('#retMsg'); m.textContent = t || ''; m.hidden = !t; };

  function timers(expSec, resendSec) {
    clearInterval(st.t); let e = expSec, r = resendSec; const te = $('#retTimer'), rb = $('#retResend');
    rb.disabled = true;
    const tick = () => {
      te.textContent = e > 0 ? String(Math.floor(e / 60)).padStart(2, '0') + ':' + String(e % 60).padStart(2, '0') : 'expired';
      rb.textContent = r > 0 ? 'Resend OTP (' + r + 's)' : 'Resend OTP'; rb.disabled = r > 0;
      if (e <= 0 && r <= 0) clearInterval(st.t); e--; r--;
    };
    tick(); st.t = setInterval(tick, 1000);
  }

  async function sendOtp(btn) {
    msg(''); const reason = $('#retReason').value.trim();
    if (reason.length < 10) return msg('Please describe the reason (at least 10 characters).');
    btn.disabled = true;
    const j = await postJSON(BASE_URL + '/send_return_otp.php', { order_id: st.order, product_id: st.product, return_reason: reason });
    btn.disabled = false;
    if (!j.ok) return msg(j.message || 'Could not send the OTP.');
    st.rid = j.return_id; $('#retEmail').textContent = j.email; $('#retOtp').value = '';
    step(2); timers(j.expires_in, j.resend_in); $('#retOtp').focus();
  }

  document.querySelectorAll('.js-return').forEach((b) => b.addEventListener('click', () => {
    Object.assign(st, { order: b.dataset.order, product: b.dataset.product, name: b.dataset.name, rid: 0 });
    $('#retProduct').textContent = st.name; $('#retReason').value = ''; msg(''); step(1); dlg.showModal();
  }));
  dlg.querySelectorAll('.js-ret-close').forEach((b) => b.addEventListener('click', () => { clearInterval(st.t); dlg.close(); }));
  $('#retSend').addEventListener('click', (e) => sendOtp(e.currentTarget));
  $('#retResend').addEventListener('click', (e) => sendOtp(e.currentTarget));
  $('#retOtp').addEventListener('input', (e) => { e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6); });

  $('#retVerify').addEventListener('click', async (e) => {
    msg(''); const otp = $('#retOtp').value;
    if (otp.length !== 6) return msg('Enter the 6-digit OTP.');
    e.currentTarget.disabled = true;
    const j = await postJSON(BASE_URL + '/verify_return_otp.php', { return_id: st.rid, otp });
    e.currentTarget.disabled = false;
    if (!j.ok) return msg(j.message || 'Verification failed.');
    clearInterval(st.t); $('#retDone').textContent = j.message; step(3);
    const cell = document.querySelector('[data-ret-cell="' + st.product + '"]');
    if (cell) { cell.textContent = ''; const s = document.createElement('span'); s.className = 'pill OTP_Verified'; s.textContent = j.label; cell.appendChild(s); }
  });
})();
