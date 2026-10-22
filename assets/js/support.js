/* Floating support widget + "Write to Us" form (Fetch -> ajax/submit_support.php) */
(function () {
  'use strict';
  const fab = document.getElementById('supportFab'), panel = document.getElementById('supportPanel'), dlg = document.getElementById('supportDialog');
  if (!fab || !dlg) return;
  const msg = document.getElementById('supMsg'), form = document.getElementById('supForm');
  const say = (t, ok) => { msg.hidden = !t; msg.textContent = t || ''; msg.className = 'alert alert-' + (ok ? 'success' : 'error'); };

  fab.addEventListener('click', () => { const open = panel.hidden; panel.hidden = !open; fab.setAttribute('aria-expanded', String(open)); });
  document.addEventListener('click', (e) => { if (!e.target.closest('#supportWidget')) { panel.hidden = true; fab.setAttribute('aria-expanded', 'false'); } });
  document.getElementById('supportWrite').addEventListener('click', () => { panel.hidden = true; say(''); dlg.showModal(); });
  document.getElementById('supClose').addEventListener('click', () => dlg.close());

  form.addEventListener('submit', async (e) => {
    e.preventDefault(); say('');
    const f = new FormData(form), btn = document.getElementById('supSend');
    if ((f.get('message') || '').trim().length < 10) return say('Please write your query (at least 10 characters).');
    btn.disabled = true;
    const j = await postJSON(BASE_URL + '/ajax/submit_support.php', Object.fromEntries(f.entries()));
    btn.disabled = false; say(j.message, j.ok);
    if (j.ok) { form.elements.message.value = ''; form.elements.order_id.value = ''; setTimeout(() => dlg.close(), 2200); }
  });
})();
