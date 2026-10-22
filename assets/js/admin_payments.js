/* Donut chart: revenue by payment method (separate IIFE: no clash with admin_charts.js) */
(function () {
  'use strict';
  const el = document.getElementById('payData'), cv = document.getElementById('payChart');
  if (!el || !cv || typeof Chart === 'undefined') return;
  const d = JSON.parse(el.textContent), total = d.amounts.reduce((a, b) => a + b, 0);
  if (total <= 0) { document.getElementById('payEmpty').hidden = false; return; }
  const inr = (v) => '₹' + Number(v).toLocaleString('en-IN', { maximumFractionDigits: 2 });
  new Chart(cv, {
    type: 'doughnut',
    data: { labels: d.labels, datasets: [{ data: d.amounts, backgroundColor: ['#0b6fb8', '#067d62', '#ff9900', '#6f42c1'], borderWidth: 2, borderColor: '#fff' }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '62%',
      plugins: { legend: { position: 'bottom' },
        tooltip: { callbacks: { label: (c) => { const pct = total ? (c.parsed / total * 100).toFixed(1) : 0; return ' ' + c.label + ': ' + inr(c.parsed) + ' (' + d.counts[c.dataIndex] + ' orders, ' + pct + '%)'; } } } } }
  });
})();
