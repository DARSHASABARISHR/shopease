/* 3D tilt for product cards: perspective + rotateX/Y driven by CSS variables, plus a moving gloss highlight. */
(function () {
  'use strict';
  if (matchMedia('(hover: none)').matches || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const MAX = 12;                                   // max tilt in degrees
  document.querySelectorAll('.grid .card').forEach((card) => {
    let raf = 0;
    card.addEventListener('pointermove', (e) => {
      if (raf) return;
      raf = requestAnimationFrame(() => {
        raf = 0; const r = card.getBoundingClientRect(), px = (e.clientX - r.left) / r.width, py = (e.clientY - r.top) / r.height;
        card.classList.add('tilting');
        card.style.setProperty('--ry', ((px - 0.5) * 2 * MAX).toFixed(2) + 'deg');
        card.style.setProperty('--rx', ((0.5 - py) * 2 * MAX).toFixed(2) + 'deg');
        card.style.setProperty('--mx', (px * 100).toFixed(1) + '%');
        card.style.setProperty('--my', (py * 100).toFixed(1) + '%');
      });
    });
    card.addEventListener('pointerleave', () => {
      card.classList.remove('tilting'); card.style.setProperty('--rx', '0deg'); card.style.setProperty('--ry', '0deg');
    });
  });
})();
