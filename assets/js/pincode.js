/* Pincode -> District / State auto-fill (https://api.postalpincode.in/pincode/{PINCODE}) */
(function () {
  'use strict';
  document.querySelectorAll('input[data-pincode]').forEach(init);

  function init(pinEl) {
    const form = pinEl.closest('form') || document;
    const city = form.querySelector('[data-pin-city]');
    const state = form.querySelector('[data-pin-state]');
    const status = form.querySelector('[data-pin-status]');
    const lock = pinEl.dataset.pinLock !== '0';          // lock the filled fields against manual typing
    let ctl = null, timer = null, filled = false;

    const say = (t, cls) => { if (status) { status.textContent = t; status.className = 'pin-status ' + (cls || ''); } };
    const setLocked = (on) => [city, state].forEach((el) => { if (el && lock) { el.readOnly = on; el.classList.toggle('autofilled', on); } });
    const clear = () => { if (filled) { if (city) city.value = ''; if (state) state.value = ''; filled = false; } };
    setLocked(true);

    pinEl.addEventListener('input', () => {
      pinEl.value = pinEl.value.replace(/\D/g, '').slice(0, 6);
      clearTimeout(timer);
      if (pinEl.value.length < 6) { if (ctl) ctl.abort(); clear(); setLocked(true); say(''); return; }
      timer = setTimeout(lookup, 250);
    });
    if (/^[1-9][0-9]{5}$/.test(pinEl.value)) lookup();

    async function lookup() {
      const pin = pinEl.value;
      if (!/^[1-9][0-9]{5}$/.test(pin)) { say('Enter a valid 6-digit pincode', 'err'); return; }
      if (ctl) ctl.abort(); ctl = new AbortController();
      say('Fetching location…', 'busy');
      try {
        const r = await fetch('https://api.postalpincode.in/pincode/' + pin, { signal: ctl.signal });
        const d = await r.json();
        const res = d && d[0];
        if (res && res.Status === 'Success' && res.PostOffice && res.PostOffice.length) {
          const po = res.PostOffice[0];
          if (city) city.value = po.District || po.Name || '';
          if (state) state.value = po.State || '';
          filled = true; setLocked(true);
          say('✔ ' + (po.District || '') + ', ' + (po.State || ''), 'ok');
          [city, state].forEach((el) => el && el.dispatchEvent(new Event('change', { bubbles: true })));
        } else {
          clear(); setLocked(true);
          say('Pincode not found. Please check the number.', 'err');
        }
      } catch (e) {
        if (e.name === 'AbortError') return;
        // Network / API problem: let the customer type manually instead of blocking checkout
        filled = false;
        [city, state].forEach((el) => { if (el) { el.readOnly = false; el.classList.remove('autofilled'); } });
        say('Could not look up the pincode right now. Please enter city and state manually.', 'err');
      }
    }
  }
})();
