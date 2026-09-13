(() => {
  'use strict';
  document.addEventListener('toggle', event => {
    const branch = event.target;
    if (!(branch instanceof HTMLDetailsElement) || !branch.open || !branch.classList.contains('site-nav-branch')) return;
    for (const other of branch.parentElement.children) {
      if (other !== branch && other.matches('details.site-nav-branch[open]')) other.open = false;
    }
  }, true);
  document.addEventListener('click', event => {
    if (!event.target.closest('.desktop-nav')) document.querySelectorAll('.desktop-nav details[open]').forEach(node => { node.open = false; });
    const trigger = event.target.closest('[data-insert-image]');
    if (!trigger) return;
    const form = trigger.closest('form'), body = form?.querySelector('[name="body"]');
    const image = form?.querySelector('[name="inline_image"]'), message = form?.querySelector('[data-image-insert-message]');
    if (!body || !image || !message) return;
    if (!/^[1-9][0-9]{0,9}$/.test(image.value)) { message.textContent = trigger.dataset.imageRequired; image.focus(); return; }
    const alt = (form.querySelector('[name="inline_image_alt"]')?.value || '').replace(/[\[\]\r\n]/g, '').slice(0, 200);
    const start = body.selectionStart ?? body.value.length, end = body.selectionEnd ?? start;
    const snippet = `\n![${alt}](media:${image.value})\n`;
    body.setRangeText(snippet, start, end, 'end'); body.dispatchEvent(new Event('input', { bubbles: true }));
    body.focus(); message.textContent = trigger.dataset.imageInserted;
  });
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const focused = event.target.closest?.('details.site-nav-branch[open]');
    const branch = focused || document.querySelector('.desktop-nav > details[open]');
    if (!branch) return;
    event.preventDefault(); branch.open = false; branch.querySelector(':scope > summary')?.focus();
  });
})();
