/* Shared feedback: animated navigation, optional images and form response. */
(() => {
  'use strict';
  const root=document.documentElement;
  const on=key=>window.CYMotion?.enabled()&&CYMotion.config()[key]&&root.dataset.economy!=='1';
  const animate=(node,frames,options)=>window.CYMotion?.animate(node,frames,options);
  const tracks=[];
  document.querySelectorAll('.desktop-nav,.account-nav,.admin-tabs,.segmented,[data-feedback-tabs]').forEach(nav=>{
    const indicator=document.createElement('i');indicator.className='nav-trace';indicator.setAttribute('aria-hidden','true');nav.classList.add('traced-nav');nav.append(indicator);let current=null,running=null;
    function move(target){
      nav.classList.toggle("nav-tracking",!!on("motion_navigation"));
      if(!target||!target.getClientRects().length){indicator.hidden=true;return;}
      indicator.hidden=!on('motion_navigation');if(indicator.hidden){running?.cancel();return;}
      const n=nav.getBoundingClientRect(),r=target.getBoundingClientRect(),from=indicator.getBoundingClientRect();
      const left=r.left-n.left+nav.scrollLeft,width=r.width;running?.cancel();
      indicator.style.left=left+'px';indicator.style.width=width+'px';current=target;
      if(from.width&&on('motion_navigation'))running=animate(indicator,[{transform:'translateX('+(from.left-r.left)+'px) scaleX('+(from.width/width)+')'},{transform:'none'}],{duration:CYMotion.duration(280)});
    }
    const selected=()=>nav.querySelector('.active,[aria-pressed="true"],[aria-current="page"]');
    const target=event=>event.target.closest('a,button');
    nav.addEventListener('pointerover',event=>move(target(event)));
    nav.addEventListener('focusin',event=>move(target(event)));
    nav.addEventListener('pointerleave',()=>move(selected()));
    nav.addEventListener('focusout',event=>{if(!nav.contains(event.relatedTarget))move(selected());});
    nav.addEventListener('click',event=>{const node=target(event);if(nav.hasAttribute('data-feedback-tabs')&&node){nav.querySelectorAll('button').forEach(b=>{b.classList.toggle('active',b===node);b.setAttribute('aria-pressed',String(b===node));});}requestAnimationFrame(()=>move(selected()||node));});
    new MutationObserver(()=>move(selected()||current)).observe(nav,{subtree:true,attributes:true,attributeFilter:['aria-pressed']});
    tracks.push(()=>move(selected()||current));move(selected());
  });
  let resize=0;window.addEventListener('resize',()=>{cancelAnimationFrame(resize);resize=requestAnimationFrame(()=>tracks.forEach(fn=>fn()));},{passive:true});
  document.addEventListener('cy:motion',()=>tracks.forEach(fn=>fn()));
  const shown=new WeakSet();
  document.querySelectorAll('.content-card img,.article-cover,.history-card img').forEach(image=>{
    const show=()=>{if(shown.has(image)||!image.naturalWidth)return;shown.add(image);if(on('motion_images'))animate(image,[{opacity:.6,transform:'scale(1.025)'},{opacity:1,transform:'scale(1)'}],{duration:CYMotion.duration(360)});};
    if(image.complete)show();else image.addEventListener('load',show,{once:true});
  });
  document.addEventListener('cy:form-feedback',event=>{
    const {form,kind}=event.detail;if(!form||!on('motion_feedback'))return;
    const target=kind==='error'?form.querySelector('.form-error'):form.querySelector('button[type="submit"]');if(!target)return;
    animate(target,kind==='error'?[{transform:'translateY(-4px)',opacity:.4},{transform:'none',opacity:1}]:[{transform:'scale(.97)'},{transform:'scale(1)'}],{duration:CYMotion.duration(200)});
  });
  document.querySelectorAll('[data-feedback-demo]').forEach(button=>button.addEventListener('click',()=>window.CYUI?.toast(button.dataset.feedbackDemo)));
})();
