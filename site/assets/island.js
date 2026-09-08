/* Original progressive enhancement. No hidden content depends on JS loading. */
(() => {
  'use strict';
  const root=document.documentElement;
  const reduced=matchMedia('(prefers-reduced-motion: reduce)');
  const allowed=()=>!reduced.matches && root.dataset.motion!=='0' && parseFloat(getComputedStyle(root).getPropertyValue('--duration'))>0;
  const results=document.querySelector('[data-catalog-results]');
  const buttons=[...document.querySelectorAll('[data-catalog-view]')];
  if(results && buttons.length){
    const key='chengyu:catalog-view:'+root.dataset.base;
    function setView(view,animate=false){
      if(!['grid','list'].includes(view))return;
      const update=()=>{results.classList.remove('layout-grid','layout-list','layout-magazine');results.classList.add('layout-'+view);buttons.forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.catalogView===view)));};
      if(animate && allowed() && window.CYLayoutMotion)window.CYLayoutMotion.change(results,update);else update();
    }
    try{const saved=localStorage.getItem(key);setView(saved || (results.classList.contains('layout-list')?'list':'grid'));}catch(_){}
    buttons.forEach(button=>button.addEventListener('click',()=>{const view=button.dataset.catalogView;setView(view,true);try{localStorage.setItem(key,view);}catch(_){}}));
  }
  document.querySelectorAll('[data-content-tabs]').forEach(group=>{
    const panels=[...group.querySelectorAll('[data-tab-panel]')];
    if(panels.length<2)return;
    const nav=document.createElement('div');nav.className='block-tabs-nav';nav.setAttribute('role','tablist');nav.setAttribute('aria-label',group.dataset.contentTabs);
    const tabs=panels.map((panel,index)=>{
      const tab=document.createElement('button');tab.type='button';tab.id=panel.id+'-tab';tab.textContent=panel.dataset.tabPanel;tab.setAttribute('role','tab');tab.setAttribute('aria-controls',panel.id);
      panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby',tab.id);panel.tabIndex=0;
      tab.addEventListener('click',()=>activate(index,true));
      tab.addEventListener('keydown',event=>{let next=index;if(event.key==='ArrowRight')next=(index+1)%panels.length;else if(event.key==='ArrowLeft')next=(index+panels.length-1)%panels.length;else if(event.key==='Home')next=0;else if(event.key==='End')next=panels.length-1;else return;event.preventDefault();activate(next,true);tabs[next].focus();});
      nav.append(tab);return tab;
    });
    function activate(index,animate){
      tabs.forEach((tab,i)=>{tab.setAttribute('aria-selected',String(i===index));tab.tabIndex=i===index?0:-1;panels[i].hidden=i!==index;});
      const panel=panels[index];if(animate && allowed() && typeof panel.animate==='function'){panel.getAnimations().forEach(a=>a.cancel());panel.animate([{opacity:.4,transform:'translateY(7px)'},{opacity:1,transform:'none'}],{duration:Math.min(360,parseFloat(getComputedStyle(root).getPropertyValue('--duration'))||0),easing:getComputedStyle(root).getPropertyValue('--curve').trim()||'ease-out'});}
    }
    group.prepend(nav);activate(0,false);
  });
})();
