(() => {
  'use strict';
  const dialog = document.querySelector('#media-picker');
  if (!dialog || !dialog.showModal || !window.CYUI) return;
  const $ = selector => dialog.querySelector(selector);
  const grid = $('[data-media-items]'), status = $('[data-media-status]');
  const preview = $('[data-media-preview]'), placeholder = $('[data-media-placeholder]'), confirm = $('[data-media-confirm]');
  const query = $('#media-search-query'), scope = $('#media-picker-scope');
  const previous = $('[data-media-previous]'), next = $('[data-media-next]');
  let target = null, trigger = null, selection = null, rows = [], cursors = [0], cursorIndex = 0, nextBefore = 0, controller = null, generation = 0, busy = false;
  const imageUrl = id => (document.documentElement.dataset.base || '') + '/media.php?id=' + id;
  const size = bytes => bytes < 1048576 ? Math.ceil(bytes / 1024) + ' KB' : (bytes / 1048576).toFixed(1) + ' MB';
  function formData(action, values) {
    const data = new FormData(); data.set('action', action); data.set('csrf', document.querySelector('meta[name="csrf-token"]')?.content || '');
    data.set('_back', location.pathname + location.search);
    Object.entries(values).forEach(([key, value]) => data.set(key, String(value)));
    return data;
  }
  function controls() {
    previous.disabled = busy || cursorIndex === 0; next.disabled = busy || !nextBefore; confirm.disabled = busy || !selection;
    grid.setAttribute('aria-busy', String(busy));
    grid.querySelectorAll('button').forEach(button => { button.disabled = busy; });
  }
  function select(row) {
    selection = row; preview.hidden = true; preview.removeAttribute('src'); placeholder.hidden = !!row;
    $('[data-media-preview-error]').hidden = true;
    $('[data-media-name]').textContent = row?.name || dialog.dataset.previewLabel;
    $('[data-media-info]').textContent = row ? '#' + row.id + ' · ' + size(row.bytes) + ' · ' + row.mime.replace('image/', '').toUpperCase() : '';
    grid.querySelectorAll('[data-media-id]').forEach(button => button.setAttribute('aria-pressed', String(row && Number(button.dataset.mediaId) === row.id)));
    if (row) { preview.alt = row.name; preview.src = imageUrl(row.id); preview.hidden = false; }
    controls();
  }
  function render() {
    grid.replaceChildren();
    for (const row of rows) {
      const button = document.createElement('button'); button.type = 'button'; button.className = 'media-choice'; button.dataset.mediaId = row.id; button.setAttribute('aria-pressed', 'false');
      const picture = document.createElement('span'); picture.className = 'media-choice-picture';
      // Large originals are loaded only after an explicit selection, not twelve at once.
      if (row.bytes <= 262144) {
        const image = document.createElement('img'); image.src = imageUrl(row.id); image.alt = ''; image.loading = 'lazy'; image.decoding = 'async';
        image.addEventListener('error', () => { image.remove(); picture.textContent = '—'; }); picture.append(image);
      } else { picture.append(placeholder.firstElementChild.cloneNode(true)); picture.title = dialog.dataset.loadPreview; }
      const label = document.createElement('span'); label.className = 'media-choice-name'; label.textContent = row.name; label.title = row.name;
      const info = document.createElement('small'); info.textContent = '#' + row.id + ' · ' + size(row.bytes);
      button.append(picture, label, info); button.addEventListener('click', () => { if (!busy) select(row); }); grid.append(button);
    }
  }
  async function load(reset = false) {
    if (reset) { cursors = [0]; cursorIndex = 0; }
    controller?.abort(); controller = new AbortController(); const current = ++generation;
    busy = true; nextBefore = 0; select(null); rows = []; render(); status.textContent = dialog.dataset.loading; controls();
    try {
      const response = await CYUI.request(formData('media_catalog', {q: query.value, scope: scope.value, before: cursors[cursorIndex]}), {signal: controller.signal});
      if (current !== generation || !dialog.open) return;
      rows = response.catalog.items; nextBefore = response.catalog.next_before; render(); status.textContent = rows.length ? '' : dialog.dataset.empty;
    } catch (error) { if (current === generation && error.name !== 'AbortError') status.textContent = error.message; }
    finally { if (current === generation) { busy = false; controls(); } }
  }
  document.querySelectorAll('[data-media-open],[data-media-clear]').forEach(button => { button.hidden = false; });
  document.addEventListener('click', event => {
    const open = event.target.closest('[data-media-open]'), clear = event.target.closest('[data-media-clear]');
    if (clear) { CYUI.assignMedia(clear.closest('.upload-field')?.querySelector('input[type="number"]'), 0); return; }
    if (!open) return;
    target = open.closest('.upload-field')?.querySelector('input[type="number"]'); if (!target) return;
    trigger = open; query.value = ''; scope.value = 'mine';
    window.CYMotion ? CYMotion.openDialog(dialog) : dialog.showModal(); load(true); query.focus();
  });
  $('[data-media-search]').addEventListener('submit', event => { event.preventDefault(); load(true); });
  scope.addEventListener('change', () => load(true));
  previous.addEventListener('click', () => { if (!busy && cursorIndex > 0) { cursorIndex--; load(); } });
  next.addEventListener('click', () => { if (!busy && nextBefore) { cursors = cursors.slice(0, cursorIndex + 1); cursors.push(nextBefore); cursorIndex++; load(); } });
  preview.addEventListener('error', () => { preview.hidden = true; $('[data-media-preview-error]').hidden = false; });
  confirm.addEventListener('click', async () => {
    if (busy || !selection || !target?.isConnected) return;
    controller?.abort(); controller = new AbortController(); const current = ++generation; busy = true; controls();
    try {
      const response = await CYUI.request(formData('media_select', {media_id: selection.id}), {signal: controller.signal});
      if (current !== generation || !dialog.open || !target?.isConnected) return;
      CYUI.assignMedia(target, response.media.id);
      const note = target.closest('.upload-field')?.querySelector('.upload-status'); if (note) note.textContent = dialog.dataset.selected;
      window.CYMotion ? CYMotion.closeDialog(dialog) : dialog.close();
    } catch (error) { if (current === generation && error.name !== 'AbortError') { status.textContent = error.message; select(null); } }
    finally { if (current === generation) { busy = false; controls(); } }
  });
  dialog.addEventListener('close', () => { generation++; controller?.abort(); busy = false; select(null); trigger?.focus(); target = null; });
})();
