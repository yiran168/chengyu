(() => {
  'use strict';
  const root = document.documentElement;
  const $ = (selector, context = document) => context.querySelector(selector);
  const $$ = (selector, context = document) => [...context.querySelectorAll(selector)];
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const motion = () => root.dataset.motion !== '0' && !reduced.matches;
  const strings = {
    network: '\u8fde\u63a5\u672a\u5b8c\u6210\uff0c\u8bf7\u786e\u8ba4\u7f51\u7edc\u540e\u91cd\u8bd5\u3002',
    copied: '\u5df2\u590d\u5236',
    copyFailed: '\u8bf7\u957f\u6309\u6216\u9009\u4e2d\u6587\u672c\u590d\u5236\u3002',
    uploading: '\u6b63\u5728\u4e0a\u4f20\u2026',
    saved: '\u5df2\u4e0a\u4f20\uff0c\u9644\u4ef6\u7f16\u53f7\uff1a',
    preview: '\u6b63\u5728\u9884\u89c8\uff1b\u70b9\u51fb\u4fdd\u5b58\u540e\u5bf9\u5168\u7ad9\u751f\u6548\u3002'
  };
  function toast(message, type = 'success') {
    let stack = $('.toast-stack');
    if (!stack) { stack = document.createElement('div'); stack.className = 'toast-stack'; stack.setAttribute('aria-live','polite'); document.body.append(stack); }
    stack.setAttribute('aria-atomic','false');
    while(stack.children.length>=4) stack.firstElementChild.remove();
    const restore=document.activeElement,node=document.createElement('div'),label=document.createElement('span'),close=document.createElement('button');
    node.className='toast '+(type==='error'?'error':type==='info'?'info':'success');label.className='toast-message';label.textContent=String(message);
    close.type='button';close.className='toast-dismiss';close.textContent='\u00d7';close.setAttribute('aria-label','\u5173\u95ed\u63d0\u793a');node.append(label,close);stack.append(node);
    const enabled=()=>window.CYMotion?.enabled()&&CYMotion.config().motion_feedback;
    let timer=0,closing=false;const delay=type==='error'?9000:5000;
    const remove=()=>{if(closing)return;closing=true;clearTimeout(timer);const wasFocused=node.contains(document.activeElement);const anim=enabled()?CYMotion.animate(node,[{opacity:1,transform:'none'},{opacity:0,transform:'translateY(8px)'}],{duration:CYMotion.duration(140)}):null;const done=()=>{node.remove();if(wasFocused&&restore?.isConnected)restore.focus({preventScroll:true});};if(anim)anim.finished.then(done,done);else done();};
    const resume=()=>{clearTimeout(timer);timer=setTimeout(remove,delay);};
    close.addEventListener('click',remove);node.addEventListener('pointerenter',()=>clearTimeout(timer));node.addEventListener('pointerleave',()=>{if(!node.contains(document.activeElement))resume();});node.addEventListener('focusin',()=>clearTimeout(timer));node.addEventListener('focusout',()=>resume());
    if(enabled())CYMotion.animate(node,[{opacity:0,transform:'translateY(10px) scale(.97)'},{opacity:1,transform:'none'}],{duration:CYMotion.duration(220)});resume();
  }
  async function request(data) {
    const response = await fetch((root.dataset.base || '') + '/action.php', {method: 'POST', body: data, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
    const text = await response.text(); let result;
    try { result = JSON.parse(text); } catch (_) { throw new Error(strings.network); }
    if (!response.ok || !result.ok) throw new Error(result.message || strings.network);
    return result;
  }
  window.CYUI = {request,toast};
  $$('form[data-async]').forEach(form => {
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (form.dataset.pending === '1' || !form.reportValidity()) return;
      const confirmation = form.dataset.confirm;
      if (confirmation && !window.confirm(confirmation)) return;
      form.dataset.pending = '1';
      const submit = event.submitter || $('button[type="submit"], button:not([type])', form);
      const data = new FormData(form);
      if (submit && submit.name) data.append(submit.name, submit.value);
      const buttons = $$('button[type="submit"],button:not([type])', form).filter(b => !b.disabled); buttons.forEach(b => { b.dataset.pendingDisabled = '1'; b.disabled = true; }); form.setAttribute('aria-busy','true');
      $$('.form-error', form).forEach(n => n.remove());
      const busyTimer=setTimeout(()=>{form.dataset.busyVisual='1';},180);
      try {
        const result = await request(data);
        document.dispatchEvent(new CustomEvent('cy:form-feedback',{detail:{form,kind:'success'}}));
        if (result.message) toast(result.message);
        if (result.redirect) {
          // Destinations are generated and validated by the server, including configured hosted checkout.
          window.location.assign(result.redirect);
        } else { form.dataset.pending = '0'; buttons.forEach(b => { b.disabled = false; delete b.dataset.pendingDisabled; }); form.removeAttribute('aria-busy'); }
      } catch (error) {
        const box = document.createElement('div'); box.className = 'form-error'; box.setAttribute('role', 'alert'); box.textContent = error.message || strings.network;
        form.append(box); document.dispatchEvent(new CustomEvent('cy:form-feedback',{detail:{form,kind:'error'}})); toast(box.textContent, 'error'); form.dataset.pending = '0'; buttons.forEach(b => { b.disabled = false; delete b.dataset.pendingDisabled; }); form.removeAttribute('aria-busy');
      } finally {clearTimeout(busyTimer);delete form.dataset.busyVisual;}
    });
  });
  $$('[data-dialog]').forEach(button => button.addEventListener('click', () => {
    const dialog = document.getElementById(button.dataset.dialog);
    if (dialog && dialog.showModal) { window.CYMotion ? CYMotion.openDialog(dialog) : dialog.showModal(); }
  }));
  $$('[data-close-dialog]').forEach(button => button.addEventListener('click', () => window.CYMotion ? CYMotion.closeDialog(button.closest('dialog')) : button.closest('dialog')?.close()));
  $$('dialog').forEach(dialog => dialog.addEventListener('click', event => { if (event.target === dialog) { const r = dialog.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) (window.CYMotion ? CYMotion.closeDialog(dialog) : dialog.close()); } }));
  document.addEventListener('keydown', event => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
      const dialog = $('#search-dialog'); if (dialog) { event.preventDefault(); (window.CYMotion ? CYMotion.openDialog(dialog) : dialog.showModal()); $('input[name="q"]', dialog)?.focus(); }
    }
  });
  const applyChoice = (type, value, persist = true, origin = null) => {
    const apply = () => {
      if (type === 'mode') { root.dataset.modeChoice = value; root.dataset.mode = value === 'auto' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : value; }
      else root.dataset[type] = value;
    if (persist && root.dataset.visitorTheme === '1') {
      try { localStorage.setItem('cy-appearance:' + (root.dataset.base || ''), JSON.stringify({theme: root.dataset.theme, mode: root.dataset.modeChoice, glass: root.dataset.glass})); } catch (_) {}
    }
      $$('[data-appearance="' + type + '"]').forEach(button => {
        const active = button.dataset.value === value; button.classList.toggle('active', active); button.setAttribute('aria-pressed', String(active));
      });
    };
    if (window.CYMotion) CYMotion.transition(apply, origin); else apply();

  };
  $$('[data-appearance]').forEach(button => {
    const current = button.dataset.appearance === 'mode' ? root.dataset.modeChoice : root.dataset[button.dataset.appearance];
    button.classList.toggle('active', button.dataset.value === current);
    button.setAttribute('aria-pressed', String(button.dataset.value === current));
    button.addEventListener('click', event => applyChoice(button.dataset.appearance, button.dataset.value, true, event));
  });
  $('[data-reset-appearance]')?.addEventListener('click', () => {
    try { localStorage.removeItem('cy-appearance:' + (root.dataset.base || '')); } catch (_) {}
    location.reload();
  });
  const scheme=matchMedia('(prefers-color-scheme: dark)'),schemeChanged=e=>{if(root.dataset.modeChoice==='auto')root.dataset.mode=e.matches?'dark':'light';};
  if(scheme.addEventListener)scheme.addEventListener('change',schemeChanged);else scheme.addListener(schemeChanged);
  $$('[data-preview]').forEach(button => button.addEventListener('click', () => {
    const theme = button.dataset.preview; applyChoice('theme', theme, false);
    const select = $('select[name="theme"]'); if (select) select.value = theme;
    $$('[data-preview]').forEach(b => b.classList.toggle('active', b === button)); toast(strings.preview);
  }));
  $$('[data-live-setting]').forEach(field => field.addEventListener('input', () => {
    const name = field.name;
    const map = {radius: '--radius', blur: '--blur', motion_duration: '--duration', hover_lift: '--lift'};
    if (map[name] && /^\d+$/.test(field.value)) root.style.setProperty(map[name], field.value + (name === 'motion_duration' ? 'ms' : 'px'));
    if (['theme','glass'].includes(name)) applyChoice(name, field.value, false);
    if (name === 'color_mode') applyChoice('mode', field.value, false);
  }));
  $$('[data-setting-search]').forEach(field => field.addEventListener('input', () => {
    const q = field.value.trim().toLowerCase();
    $$('[data-setting-row]').forEach(row => { row.hidden = q !== '' && !row.textContent.toLowerCase().includes(q) && !row.dataset.settingRow.toLowerCase().includes(q); });
  }));
  $$('[data-copy]').forEach(button => button.addEventListener('click', async () => {
    const selector = button.dataset.copyTarget;
    const value = selector ? ($(selector)?.value || $(selector)?.textContent || '') : (button.dataset.copy || location.href);
    try { await navigator.clipboard.writeText(value); toast(strings.copied); } catch (_) { toast(strings.copyFailed, 'info'); }
  }));
  $$('input[data-upload-target]').forEach(input => input.addEventListener('change', async () => {
    if (!input.files?.[0]) return;
    const target = document.getElementById(input.dataset.uploadTarget); const status = input.closest('.upload-field')?.querySelector('.upload-status');
    const data = new FormData(); data.append('file', input.files[0]); data.append('action', 'upload'); data.append('csrf', $('meta[name="csrf-token"]')?.content || '');
    if (input.dataset.private === '1') data.append('private', '1');
    input.disabled = true; if (status) status.textContent = strings.uploading;
    try { const result = await request(data); if (target) target.value = result.media_id; if (status) status.textContent = strings.saved + result.media_id; toast(result.message); }
    catch (e) { if (status) status.textContent = e.message; toast(e.message, 'error'); }
    finally { input.disabled = false; input.value = ''; }
  }));
  const progress = $('.reading-progress'); let ticking = false;
  const updateProgress = () => { const maximum = document.documentElement.scrollHeight - innerHeight; if (progress) progress.style.transform = 'scaleX(' + (maximum > 0 ? Math.min(1, scrollY / maximum) : 0) + ')'; ticking = false; };
  if (progress) { window.addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(updateProgress); } }, {passive:true}); updateProgress(); }
  $('[data-back-top]')?.addEventListener('click', () => window.scrollTo({top:0, behavior:motion() ? 'smooth' : 'auto'}));
  // Native navigation stays immediate; browsers may progressively add view transitions.
  window.addEventListener('pageshow', () => { document.body.classList.remove('leaving'); $$('form[data-async]').forEach(f => { f.dataset.pending = '0'; $$('[data-pending-disabled]', f).forEach(b => { b.disabled = false; delete b.dataset.pendingDisabled; }); f.removeAttribute('aria-busy'); }); });
})();
