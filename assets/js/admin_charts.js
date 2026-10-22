(function () {
  'use strict';
  if (typeof Chart === 'undefined') { const n = document.getElementById('chartErr'); if (n) { n.hidden = false; n.textContent = 'Chart.js could not be loaded (check your internet connection).'; } return; }
  const inr = (v) => '₹' + Number(v).toLocaleString('en-IN');
  const palette = ['#ff9900', '#232f3e', '#067d62', '#0b6fb8', '#b12704'];
  const charts = {}; let range = 'daily';
  const $ = (id) => document.getElementById(id);

  function make(id, cfg) { if (charts[id]) charts[id].destroy(); charts[id] = new Chart($(id), cfg); }

  async function load() {
    const err = $('chartErr'); err.hidden = true;
    let d;
    try { const r = await fetch(BASE_URL + '/admin/chart_data.php?range=' + range, { credentials: 'same-origin' }); d = await r.json(); }
    catch (e) { err.hidden = false; err.textContent = 'Could not load chart data.'; return; }
    if (d.error) { err.hidden = false; err.textContent = d.error; return; }
    draw(d);
  }

  function draw(d) {
    /* Sales: revenue bars + order-count line */
    make('salesChart', {
      data: { labels: d.sales.labels, datasets: [
        { type: 'bar', label: 'Revenue (₹)', data: d.sales.revenue, backgroundColor: 'rgba(255,153,0,.8)', borderRadius: 4, yAxisID: 'y' },
        { type: 'line', label: 'Orders', data: d.sales.orders, borderColor: '#232f3e', backgroundColor: '#232f3e', tension: .3, pointRadius: 3, yAxisID: 'y1' } ] },
      options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        plugins: { tooltip: { callbacks: { label: (c) => c.dataset.yAxisID === 'y' ? ' Revenue: ' + inr(c.parsed.y) : ' Orders: ' + c.parsed.y } } },
        scales: { y: { beginAtZero: true, ticks: { callback: (v) => inr(v) }, title: { display: true, text: 'Revenue' } },
                  y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { precision: 0 }, title: { display: true, text: 'Orders' } } } }
    });

    /* Depletion: units sold per day/month for the top 5 products */
    const none = !d.depletion.series.length; $('depEmpty').hidden = !none;
    make('depChart', {
      type: 'line',
      data: { labels: d.depletion.labels, datasets: d.depletion.series.map((s, i) => ({ label: s.name + ' (' + s.total + ')', data: s.data, borderColor: palette[i % 5], backgroundColor: palette[i % 5], tension: .3, pointRadius: 2 })) },
      options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'bottom' } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Units sold (stock reduced)' } } } }
    });

    /* Current stock levels, colour coded */
    make('stockChart', {
      type: 'bar',
      data: { labels: d.stock.labels, datasets: [{ label: 'In stock', data: d.stock.values, backgroundColor: d.stock.colors, borderRadius: 4 }] },
      options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Units left' } } } }
    });
    $('stockOut').textContent = d.stock.out; $('stockLow').textContent = d.stock.low; $('stockTh').textContent = d.stock.threshold;

    /* Recent movements table */
    const tb = $('moveBody'); tb.textContent = '';
    if (!d.moves.length) { const tr = tb.insertRow(); const td = tr.insertCell(); td.colSpan = 4; td.textContent = 'No stock movements yet.'; }
    d.moves.forEach((m) => {
      const tr = tb.insertRow();
      tr.insertCell().textContent = m.product;
      const q = tr.insertCell(); q.textContent = (m.qty > 0 ? '+' : '') + m.qty; q.style.fontWeight = '700'; q.style.color = m.qty < 0 ? '#b12704' : '#067d62';
      tr.insertCell().textContent = ({ order: 'Order' + (m.order ? ' #' + m.order : ''), cancel: 'Cancelled' + (m.order ? ' #' + m.order : ''), admin_edit: 'Admin stock edit' })[m.reason] || m.reason;
      tr.insertCell().textContent = m.time;
    });
  }

  document.querySelectorAll('#rangeSeg button').forEach((b) => b.addEventListener('click', () => {
    document.querySelectorAll('#rangeSeg button').forEach((x) => x.classList.toggle('on', x === b));
    range = b.dataset.range; load();
  }));
  load();
})();
