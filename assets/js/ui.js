/* Theme toggle, image skeleton loaders, quick-view modal */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);

  /* theme */
  const tb = $('#themeBtn');
  if (tb) tb.addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next; try { localStorage.setItem('se-theme', next); } catch (e) {}
  });

  /* shimmer until each image has loaded */
  function arm(box) {
    const img = $('img', box); if (!img) return;
    const done = () => box.classList.add('loaded');
    if (img.complete && img.naturalWidth) done(); else { img.addEventListener('load', done, { once: true }); img.addEventListener('error', done, { once: true }); }
  }
  document.querySelectorAll('.skeleton').forEach(arm);

  /* quick view */
  const dlg = $('#quickDialog'); if (!dlg) return;
  let pid = 0;
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-quick]'); if (!b) return;
    e.preventDefault(); pid = b.dataset.quick;
    const img = $('#qvImg'), box = img.parentNode, name = $('#qvName');
    box.classList.remove('loaded'); name.classList.remove('loaded'); name.textContent = ''; img.removeAttribute('src');
    ['#qvPrice', '#qvMrp', '#qvOff', '#qvDesc', '#qvStock'].forEach((s) => ($(s).textContent = ''));
    dlg.showModal();
    try {
      const d = await (await fetch(BASE_URL + '/ajax/quick_view.php?id=' + encodeURIComponent(pid))).json();
      if (!d.ok) throw 0;
      img.src = d.image; arm(box); name.textContent = d.name; name.classList.add('loaded');
      $('#qvPrice').textContent = d.price; $('#qvMrp').textContent = d.mrp; $('#qvOff').textContent = d.off ? d.off + '% off' : '';
      $('#qvDesc').textContent = d.description;
      $('#qvRating').textContent = '★ ' + d.rating.toFixed(1) + ' (' + d.count.toLocaleString('en-IN') + ')';
      const st = $('#qvStock'); st.className = 'stock ' + (d.stock <= 0 ? 'out' : d.stock <= 10 ? 'low' : 'in'); st.textContent = d.stock <= 0 ? I18N.out : I18N.in;
      $('#qvAdd').disabled = d.stock <= 0; $('#qvAdd').dataset.addCart = d.id; $('#qvLink').href = d.url;
    } catch (err) { dlg.close(); window.toast && toast('Could not load the product.', true); }
  });
  $('#qvClose').addEventListener('click', () => dlg.close());
  dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
  $('#qvAdd').addEventListener('click', () => setTimeout(() => dlg.close(), 350));   // app.js handles [data-add-cart]
})();
