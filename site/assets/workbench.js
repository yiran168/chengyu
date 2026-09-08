/* Optional pointer light, one animation frame per affected surface; no idle loop. */
(() => {
  'use strict';
  const preference = matchMedia('(prefers-reduced-motion: reduce)');
  const pointer = matchMedia('(hover: hover) and (pointer: fine)');
  document.querySelectorAll('[data-workbench-glow]').forEach(surface => {
    let frame = 0, x = 0, y = 0;
    const cancel = () => { if (frame) cancelAnimationFrame(frame); frame = 0; };
    surface.addEventListener('pointermove', event => {
      if (preference.matches || !pointer.matches || document.documentElement.dataset.motion !== '1' || document.hidden) return;
      const rect = surface.getBoundingClientRect(); x = event.clientX - rect.left; y = event.clientY - rect.top;
      if (!frame) frame = requestAnimationFrame(() => {
        frame = 0; surface.style.setProperty('--pointer-x', x.toFixed(1) + 'px'); surface.style.setProperty('--pointer-y', y.toFixed(1) + 'px');
      });
    }, {passive:true});
    surface.addEventListener('pointerleave', cancel, {passive:true});
    document.addEventListener('visibilitychange', cancel);
  });
})();
