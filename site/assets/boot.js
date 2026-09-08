/* Apply optional visitor preferences before first paint; failures never block content. */
(() => {
  'use strict';
  const root = document.documentElement;
  const allowed = root.dataset.visitorTheme === '1';
  let preferences = {};
  try { if (allowed) preferences = JSON.parse(localStorage.getItem('cy-appearance:' + (root.dataset.base || '')) || '{}'); } catch (_) {}
  if (['tide', 'dusk', 'paper', 'graphite', 'citrus'].includes(preferences.theme)) root.dataset.theme = preferences.theme;
  if (['none', 'frost', 'prism', 'liquid'].includes(preferences.glass)) root.dataset.glass = preferences.glass;
  const mode = ['light', 'dark', 'auto'].includes(preferences.mode) ? preferences.mode : (root.dataset.mode || 'light');
  root.dataset.modeChoice = mode;
  root.dataset.mode = mode === 'auto' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : mode;
})();
