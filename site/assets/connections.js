/* Grouped navigation and finite icon motion; existing motion settings remain authoritative. */
(() => {
  'use strict';
  // A separate same-origin response ends CSP form submission before cross-origin navigation.
  const continuation=document.querySelector('a[data-external-handoff]');
  if(continuation){try{const target=new URL(continuation.href);if(target.protocol==='https:'&&!target.username&&!target.password)window.location.replace(target.href);}catch(_){/* The accessible anchor remains available. */}}
  const root=document.documentElement,search=document.querySelector('[data-admin-search]'),groups=[...document.querySelectorAll('[data-nav-section]')];
  const feedback=document.querySelector('[data-admin-results]');let saved=null;
  function filter(){
    const query=search.value.trim().toLocaleLowerCase();let count=0;
    if(query&&!saved)saved=new Map(groups.map(group=>[group,group.open]));
    groups.forEach(group=>{
      let found=0;group.querySelectorAll('a').forEach(link=>{const match=!query||link.textContent.toLocaleLowerCase().includes(query);link.hidden=!match;if(match){found++;count++;}});
      group.hidden=!!query&&!found;if(query)group.open=!!found;else if(saved)group.open=saved.get(group);
    });
    if(!query)saved=null;
    if(feedback){feedback.hidden=!query;feedback.textContent=count?'\u627e\u5230 '+count+' \u4e2a\u63a7\u5236\u5165\u53e3':'\u6ca1\u6709\u5339\u914d\u9879\uff0c\u8bf7\u6362\u4e2a\u5173\u952e\u8bcd';}
  }
  if(search){search.addEventListener('input',filter);search.addEventListener('search',filter);search.addEventListener('keydown',event=>{if(event.key==='Escape'&&search.value){event.preventDefault();event.stopPropagation();search.value='';filter();}});document.addEventListener('keydown',event=>{if(event.altKey&&event.key==='/'&&!event.ctrlKey&&!event.metaKey){event.preventDefault();if(matchMedia('(max-width:960px)').matches&&!document.body.classList.contains('sidebar-open'))document.querySelector('[data-admin-menu]')?.click();search.focus();}});}
  // Animate only the small emblem, never a form or its text. Interrupted motion is
  // cancelled on blur/visibility and entirely bypassed for reduced motion/economy.
  const active=new Map();const permitted=()=>window.CYMotion?.enabled()&&CYMotion.config().motion_sheen&&CYMotion.duration()>0&&root.dataset.economy!=='1'&&!document.hidden;
  function cancel(node){const a=active.get(node);if(a){active.delete(node);a.cancel();}}
  function animate(card){const node=card.querySelector('.recognition-medallion,.provider-mark');if(!node||active.has(node)||!permitted())return;
    const a=CYMotion.animate(node,[{transform:'translateY(0) rotate(0deg)'},{transform:'translateY(-3px) rotate(-4deg)',offset:.45},{transform:'translateY(0) rotate(0deg)'}],{duration:CYMotion.duration(600)});
    if(a){active.set(node,a);a.finished.then(()=>cancel(node),()=>{if(active.get(node)===a)active.delete(node);});}}
  document.querySelectorAll('.recognition-card,.integration-card').forEach(card=>{card.addEventListener('pointerenter',event=>{if(event.pointerType!=='touch')animate(card);});card.addEventListener('focusin',()=>animate(card));card.addEventListener('focusout',event=>{if(!card.contains(event.relatedTarget)){const node=card.querySelector('.recognition-medallion,.provider-mark');cancel(node);}});});
  function clear(){[...active.keys()].forEach(cancel);}
  document.addEventListener('cy:motion',()=>{if(!permitted())clear();});document.addEventListener('visibilitychange',()=>{if(document.hidden)clear();});window.addEventListener('pagehide',clear);
  window.CYConnections={active:()=>active.size,filter:()=>search&&filter()};
})();
