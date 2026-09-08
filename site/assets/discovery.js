/* Original progressive search and private reading state. No framework or remote assets. */
(() => {
  'use strict';
  const root=document.documentElement, base=root.dataset.base||'';
  const box=document.querySelector('[data-search-suggestions]');
  if(box){
    const dialog=box.closest('dialog'),input=dialog.querySelector('input[name="q"]'),results=box.querySelector('.search-results'),status=box.querySelector('[role="status"]');
    let timer=0,serial=0,controller=null,active=-1,composing=false,options=[];
    input.setAttribute('role','combobox');input.setAttribute('aria-autocomplete','list');input.setAttribute('aria-controls',results.id);input.setAttribute('aria-expanded','false');
    const clear=()=>{results.replaceChildren();options=[];active=-1;input.removeAttribute('aria-activedescendant');input.setAttribute('aria-expanded','false');};
    const select=index=>{active=index;options.forEach((option,i)=>option.setAttribute('aria-selected',String(i===index)));if(options[index]){input.setAttribute('aria-activedescendant',options[index].id);options[index].scrollIntoView({block:'nearest'});}else input.removeAttribute('aria-activedescendant');};
    async function search(query,ticket){
      controller=typeof AbortController==='function'?new AbortController():null;
      box.setAttribute('aria-busy','true');
      try{
        const response=await fetch(box.dataset.endpoint+'&q='+encodeURIComponent(query),{credentials:'same-origin',headers:{Accept:'application/json'},signal:controller?.signal});
        if(!response.ok)throw new Error('search');const payload=await response.json();
        if(ticket!==serial || !dialog.open)return;
        clear();
        for(const row of (Array.isArray(payload.items)?payload.items:[]).slice(0,6)){
          // Server generates root-relative same-site routes, never arbitrary redirect URLs.
          if(typeof row.url!=='string'||!row.url.startsWith(base+'/index.php?')||typeof row.title!=='string')continue;
          const option=document.createElement('a');option.href=row.url;option.className='search-result';option.id='search-option-'+options.length;
          option.setAttribute('role','option');option.setAttribute('aria-selected','false');option.tabIndex=-1;
          const label=document.createElement('strong');label.textContent=row.title;
          const arrow=document.createElement('span');arrow.textContent='\u2197';arrow.setAttribute('aria-hidden','true');option.append(label,arrow);
          const index=options.length;option.addEventListener('pointermove',()=>select(index));options.push(option);results.append(option);
        }
        status.textContent=options.length?options.length+' \u6761\u5339\u914d \u00b7 \u4e0a\u4e0b\u952e\u9009\u62e9':box.dataset.empty;
        input.setAttribute('aria-expanded',String(options.length>0));
        if(window.CYMotion?.enabled())CYMotion.animate(results,[{opacity:.3,transform:'translateY(5px)'},{opacity:1,transform:'none'}],{duration:CYMotion.duration(180)});
      }catch(error){if(ticket===serial&&error.name!=='AbortError'){clear();status.textContent=box.dataset.error;}}
      finally{if(ticket===serial)box.removeAttribute('aria-busy');}
    }
    function schedule(){clearTimeout(timer);controller?.abort();const ticket=++serial;clear();status.textContent='';box.removeAttribute('aria-busy');const q=input.value.trim();if(composing||q.length<2)return;timer=setTimeout(()=>search(q,ticket),200);}
    input.addEventListener('input',schedule);input.addEventListener('compositionstart',()=>{composing=true;schedule();});input.addEventListener('compositionend',()=>{composing=false;schedule();});
    input.addEventListener('keydown',event=>{
      if(event.isComposing||composing)return;
      if(event.key==='ArrowDown'&&options.length){event.preventDefault();select((active+1)%options.length);}
      else if(event.key==='ArrowUp'&&options.length){event.preventDefault();select((active-1+options.length)%options.length);}
      else if(event.key==='Enter'&&options[active]){event.preventDefault();location.assign(options[active].href);}
      else if(event.key==='Escape'){
        event.preventDefault();event.stopPropagation();const hadOptions=options.length>0;
        ++serial;clearTimeout(timer);controller?.abort();clear();status.textContent='';box.removeAttribute('aria-busy');
        // Search inputs otherwise consume Escape to clear text without closing the dialog.
        if(!hadOptions){if(window.CYMotion)CYMotion.closeDialog(dialog);else dialog.close();}
      }
    });
    dialog.addEventListener('close',()=>{++serial;clearTimeout(timer);controller?.abort();clear();status.textContent='';box.removeAttribute('aria-busy');});
  }
  const marker=document.querySelector('[data-reading-content]');
  if(marker){
    const article=document.querySelector('.article-panel');let last=-1,lastAt=0,ticking=false;
    function progress(){const rect=article.getBoundingClientRect();return Math.max(0,Math.min(100,Math.round((innerHeight-rect.top)/Math.max(1,rect.height)*100)));}
    function record(force=false){
      if(!article)return;const value=progress(),now=Date.now();if(value<=last||(!force&&now-lastAt<45000))return;
      last=value;lastAt=now;const data=new FormData();data.set('action','history_record');data.set('csrf',document.querySelector('meta[name="csrf-token"]')?.content||'');data.set('content_id',marker.dataset.readingContent);data.set('progress',String(value));
      // Failed tracking must never interrupt reading. No external analytics or identifiers.
      fetch(base+'/action.php',{method:'POST',body:data,credentials:'same-origin',keepalive:true,headers:{Accept:'application/json'}}).catch(()=>{});
    }
    record();window.addEventListener('scroll',()=>{if(ticking)return;ticking=true;requestAnimationFrame(()=>{ticking=false;record();});},{passive:true});
    document.addEventListener('visibilitychange',()=>{if(document.hidden)record(true);});
  }
})();
