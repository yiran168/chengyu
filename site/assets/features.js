/* Shared, optional UI enhancements; server forms remain authoritative. */
(() => {
  'use strict';
  document.querySelectorAll('[data-thread-mode]').forEach(select=>{
    const group=select.closest('.thread-editor').querySelector('[data-poll-fields]');
    const privacy=group.querySelector('[name=poll_results]'),deadline=group.querySelector('[name=poll_closes_at]');
    const update=()=>{const poll=select.value==='poll';group.hidden=!poll;group.querySelectorAll('input,textarea,select').forEach(n=>n.disabled=!poll);if(deadline)deadline.required=poll && privacy?.value==='closed';};
    select.addEventListener('change',update);privacy?.addEventListener('change',update);update();
  });
  document.querySelectorAll('[data-email-code]').forEach(button=>button.addEventListener('click',async()=>{
    if(button.disabled || !window.CYUI)return;
    const form=button.closest('form'),email=form.querySelector('[name=email]'),status=form.querySelector('[data-code-status]');
    if(!email || !email.reportValidity())return;
    const data=new FormData();data.set('action','email_code_request');data.set('email',email.value);data.set('purpose',button.dataset.emailCode);data.set('csrf',form.querySelector('[name=csrf]')?.value||'');data.set('website',form.querySelector('[name=website]')?.value||'');
    const label=button.textContent;button.disabled=true;status.textContent='';
    try { const result=await CYUI.request(data);status.textContent=result.message;CYUI.toast(result.message);let remaining=60;button.textContent=remaining+'s';const interval=setInterval(()=>{remaining--;button.textContent=remaining+'s';if(remaining<=0){clearInterval(interval);button.disabled=false;button.textContent=label;}},1000); }
    catch(error){status.textContent=error.message;CYUI.toast(error.message,'error');button.disabled=false;button.textContent=label;}
  }));
  if(document.documentElement.dataset.readingTools==='1') {
    const bodies=[...document.querySelectorAll('[data-reading-body]')],headings=bodies.flatMap(n=>[...n.querySelectorAll('h2,h3')]);
    if(headings.length>1) {
      const details=document.createElement('details');details.className='reading-outline';details.open=true;
      const summary=document.createElement('summary');summary.textContent='\u672c\u6587\u5bfc\u822a';
      const nav=document.createElement('nav');nav.setAttribute('aria-label',summary.textContent);const list=document.createElement('ol');
      headings.forEach((heading,index)=>{if(!heading.id)heading.id='reading-'+index;const item=document.createElement('li');item.className=heading.tagName==='H3'?'subheading':'';const link=document.createElement('a');link.href='#'+heading.id;link.textContent=heading.textContent;item.append(link);list.append(item);});
      nav.append(list);details.append(summary,nav);bodies[0].before(details);
    }
  }
})();
