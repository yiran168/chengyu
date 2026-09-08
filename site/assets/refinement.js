/* Progressive UI: keyed layout motion, reading focus and authoritative price previews. */
(() => {
  'use strict';
  const root=document.documentElement, moving=new Map();
  const permitted=()=>window.CYMotion?.enabled() && CYMotion.config().motion_layout && root.dataset.economy!=='1';
  const cancel=()=>{moving.forEach(animation=>animation.cancel());moving.clear();};
  // Read current visual positions BEFORE cancelling. Rapid reversals start where the
  // previous animation actually is, not where a stale keyframe said it should be.
  window.CYLayoutMotion={
    change(container,update){
      const before=new Map([...container.querySelectorAll('[data-layout-key]')].map(n=>[n.dataset.layoutKey,n.getBoundingClientRect()]));
      cancel();update();if(!permitted())return;
      container.querySelectorAll('[data-layout-key]').forEach(node=>{
        const first=before.get(node.dataset.layoutKey),last=node.getBoundingClientRect();
        if(!first)return;
        const dx=first.left-last.left,dy=first.top-last.top;
        if(Math.abs(dx)+Math.abs(dy)<.5)return;
        const animation=CYMotion.animate(node,[{transform:`translate(${dx}px,${dy}px)`},{transform:'none'}],{duration:CYMotion.duration(460)});
        if(animation){moving.set(node,animation);animation.finished.then(()=>{if(moving.get(node)===animation){moving.delete(node);animation.cancel();}},()=>{if(moving.get(node)===animation)moving.delete(node);});}
      });
    },active:()=>moving.size,cancel
  };
  document.addEventListener('cy:motion',()=>{if(!permitted())cancel();});
  document.addEventListener('visibilitychange',()=>{if(document.hidden)cancel();});
  window.addEventListener('pagehide',cancel);

  const article=document.querySelector('[data-focus-article]');
  if(article && root.dataset.readingFocus==='1') {
    const grid=article.closest('.main-grid'),aside=grid?.querySelector(':scope > aside');
    if(grid){
      const button=document.createElement('button');button.type='button';button.className='btn secondary small reading-focus-toggle';
      button.textContent='\u4e13\u6ce8\u9605\u8bfb';button.setAttribute('aria-pressed','false');article.querySelector('.article-labels')?.append(button);
      button.addEventListener('click',()=>{
        const on=button.getAttribute('aria-pressed')!=='true';
        // A short opacity arrival avoids scaling text or delaying the actual layout.
        grid.classList.toggle('reading-focused',on);if(aside)aside.hidden=on;
        button.setAttribute('aria-pressed',String(on));button.textContent=on?'\u6062\u590d\u5e03\u5c40':'\u4e13\u6ce8\u9605\u8bfb';
        if(permitted())CYMotion.animate(article,[{opacity:.75},{opacity:1}],{duration:CYMotion.duration(200)});
      });
    }
  }

  document.querySelectorAll('[data-price-quote]').forEach(panel=>{
    const form=panel.closest('form'),refresh=panel.querySelector('[data-quote-refresh]'),status=panel.querySelector('[data-quote-status]');
    const value=panel.querySelector('[data-quote-amount]'),base=panel.querySelector('[data-quote-base]'),label=panel.querySelector('[data-quote-label]'),ceiling=form.elements.price_ceiling;
    const variant=form.elements.variant_id,coupon=form.elements.coupon;
    let timer=0,sequence=0,request=null;
    refresh.hidden=false;
    const cancelRequest=()=>{sequence++;clearTimeout(timer);request?.abort();request=null;panel.removeAttribute('aria-busy');};
    async function update(){
      cancelRequest();const current=sequence;
      if(variant && !variant.value){status.textContent='\u8bf7\u5148\u9009\u62e9\u89c4\u683c\u3002';return;}
      request=new AbortController();panel.setAttribute('aria-busy','true');
      status.textContent='\u6b63\u5728\u6838\u5bf9\u5f53\u524d\u4f18\u60e0\u2026';
      try{
        const query=new URL(panel.dataset.endpoint,document.baseURI);
        if(variant)query.searchParams.set('variant_id',variant.value);
        if(coupon)query.searchParams.set('coupon',coupon.value.trim());
        const response=await fetch(query,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:request.signal});
        const data=await response.json();if(current!==sequence)return;
        if(!response.ok || !data.ok)throw new Error(data.message||'\u6682\u65f6\u65e0\u6cd5\u83b7\u53d6\u62a5\u4ef7\u3002');
        const q=data.quote;
        if(!Number.isSafeInteger(q.amount)||q.amount<0||q.amount>1000000000||typeof q.formatted!=='string')throw new Error('\u62a5\u4ef7\u683c\u5f0f\u65e0\u6548\u3002');
        value.textContent=q.formatted;label.textContent=String(q.label||'').slice(0,100);base.textContent=String(q.base_formatted||'').slice(0,100);
        base.hidden=q.amount===q.base_amount;ceiling.value=String(q.amount);if(form.elements.price_currency)form.elements.price_currency.value=q.currency;
        status.textContent='\u5df2\u6838\u5bf9\uff0c\u5c1a\u672a\u6263\u6b3e\u3002\u62a5\u4ef7\u4e0d\u9884\u7559\u5e93\u5b58\uff1b\u4ef7\u683c\u4e0a\u6da8\u65f6\u9700\u91cd\u65b0\u786e\u8ba4\u3002';
        if(window.CYMotion?.enabled())CYMotion.animate(value,[{opacity:.5},{opacity:1}],{duration:CYMotion.duration(180)});
      }catch(error){if(current===sequence&&error.name!=='AbortError')status.textContent=String(error.message||'\u8bf7\u91cd\u65b0\u6838\u5bf9\u4ef7\u683c\u3002').slice(0,250);}
      finally{if(current===sequence){request=null;panel.removeAttribute('aria-busy');}}
    }
    refresh.addEventListener('click',update);
    variant?.addEventListener('change',()=>{
      // The selected option is server-rendered. Keep a safe ceiling even before
      // the asynchronous quote returns, including on a disconnected browser.
      const amount=variant.selectedOptions[0]?.dataset.amount;if(amount!==undefined)ceiling.value=amount;
      update();
    });
    coupon?.addEventListener('input',()=>{cancelRequest();timer=setTimeout(update,250);});
    form.addEventListener('submit',cancelRequest);window.addEventListener('pagehide',cancelRequest);
  });
})();
