(() => {
  'use strict';
  const search = document.querySelector('[data-visual-search]');
  search?.addEventListener('input', () => {
    const query = search.value.trim().toLocaleLowerCase();
    document.querySelectorAll('[data-visual-name]').forEach(tile => { tile.hidden = !tile.dataset.visualName.includes(query); });
  });
  document.querySelectorAll('[data-cover-pick]').forEach(input => input.addEventListener('change', () => {
    const select = input.closest('form')?.querySelector('[name="cover_art"]');
    if (select) { select.value = input.value; select.dispatchEvent(new Event('change', {bubbles: true})); }
  }));
  const form = document.querySelector('.visual-form');
  if (!form) return;
  const select = form.querySelector('[data-visual-assets]'), media = form.querySelector('[name="media_id"]');
  const preview = document.querySelector('[data-visual-preview] img'), fallback = document.querySelector('[data-visual-fallback]');
  const originals = JSON.parse(select.dataset.visualAssets), defaultSrc = document.querySelector('[data-visual-preview]').dataset.defaultImage || '';
  function show() {
    const id = /^[1-9][0-9]{0,9}$/.test(media.value) ? media.value : '';
    const src = id ? `${document.documentElement.dataset.base}/media.php?id=${id}` : originals[select.value] || defaultSrc;
    preview.hidden = !src; fallback.hidden = !!src;
    if (src) preview.src = src;
  }
  preview.addEventListener('error', () => { preview.hidden = true; fallback.hidden = false; });
  select.addEventListener('change', () => { if (select.value) media.value = '0'; show(); });
  media.addEventListener('input', () => { if (Number(media.value) > 0) select.value = ''; show(); });
  media.addEventListener('change', () => { if (Number(media.value) > 0) select.value = ''; show(); });
})();
