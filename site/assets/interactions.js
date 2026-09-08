/* Native semantics first; animation and richer controls progressively enhance them. */
(() => {
  'use strict';
  const root=document.documentElement,engine=()=>window.CYMotion,activeDisclosures=new Map();
  function finishDisclosure(details,record){if(activeDisclosures.get(details)!==record)return;activeDisclosures.delete(details);record.animation?.cancel();details.open=record.open;details.style.removeProperty('height');details.style.removeProperty('overflow');}
  document.addEventListener('click',event=>{
    const summary=event.target.closest('summary');if(!summary||summary.parentElement.tagName!=='DETAILS'||event.defaultPrevented||event.button!==0)return;
    const motion=engine();if(!motion?.enabled()||!motion.config().motion_disclosures||!summary.parentElement.animate)return;
    const details=summary.parentElement,old=activeDisclosures.get(details),open=old?!old.open:!details.open,start=details.getBoundingClientRect().height;
    event.preventDefault();if(old){activeDisclosures.delete(details);old.animation.cancel();}
    details.open=true;details.style.removeProperty('height');
    const expanded=details.getBoundingClientRect().height,style=getComputedStyle(details),collapsed=summary.getBoundingClientRect().height+parseFloat(style.paddingTop)+parseFloat(style.paddingBottom)+parseFloat(style.borderTopWidth)+parseFloat(style.borderBottomWidth);
    details.style.height=start+'px';details.style.overflow='hidden';
    const animation=motion.animate(details,[{height:start+'px'},{height:(open?expanded:collapsed)+'px'}],{duration:motion.duration(340),fill:'both'});
    const record={animation,open};activeDisclosures.set(details,record);if(!animation){finishDisclosure(details,record);return;}
    animation.finished.then(()=>finishDisclosure(details,record),()=>finishDisclosure(details,record));
  });
  document.addEventListener('cy:motion',event=>{if(!event.detail.enabled||!event.detail.config.motion_disclosures)activeDisclosures.forEach((r,d)=>{r.animation?.cancel();finishDisclosure(d,r);});});
  const sidebar=document.querySelector('.admin-sidebar'),trigger=document.querySelector('.admin-menu-button'),shell=document.querySelector('.admin-shell');
  if(sidebar&&trigger){
    const media=matchMedia('(max-width:960px)'),focusable=()=>[...sidebar.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),summary,[tabindex="0"]')].filter(n=>!n.hidden&&n.getClientRects().length);
    let previous=null;
    function toggle(open){document.body.classList.toggle('sidebar-open',open);trigger.setAttribute('aria-expanded',String(open));if(shell)shell.inert=open;
      if(open){previous=document.activeElement;sidebar.setAttribute('role','dialog');sidebar.setAttribute('aria-modal','true');(sidebar.querySelector('[data-admin-search]')||sidebar).focus();}
      else{sidebar.removeAttribute('role');sidebar.removeAttribute('aria-modal');if(previous?.isConnected)previous.focus();previous=null;}}
    document.querySelectorAll('[data-admin-menu]').forEach(button=>button.addEventListener('click',()=>toggle(!document.body.classList.contains('sidebar-open'))));
    document.querySelectorAll('[data-admin-close],[data-admin-dismiss]').forEach(button=>button.addEventListener('click',()=>toggle(false)));
    document.addEventListener('keydown',event=>{if(!document.body.classList.contains('sidebar-open'))return;if(event.key==='Escape'){event.preventDefault();toggle(false);}if(event.key==='Tab'){const list=focusable(),first=list[0],last=list[list.length-1];if(!first){event.preventDefault();sidebar.focus();}else if(event.shiftKey&&(document.activeElement===first||!sidebar.contains(document.activeElement))){event.preventDefault();last.focus();}else if(!event.shiftKey&&(document.activeElement===last||!sidebar.contains(document.activeElement))){event.preventDefault();first.focus();}}});
    const resize=()=>{if(!media.matches)toggle(false);};media.addEventListener?media.addEventListener('change',resize):media.addListener(resize);
    window.addEventListener('pageshow',()=>toggle(false));
  }
  const words={close:'\u5173\u95ed',prev:'\u4e0a\u4e00\u5f20',next:'\u4e0b\u4e00\u5f20',zoom:'\u5207\u6362\u7f29\u653e',view:'\u67e5\u770b\u5927\u56fe',failed:'\u56fe\u7247\u52a0\u8f7d\u5931\u8d25',copy:'\u590d\u5236\u94fe\u63a5',share:'\u5206\u4eab\u94fe\u63a5',copied:'\u94fe\u63a5\u5df2\u590d\u5236',manual:'\u8bf7\u9009\u4e2d\u94fe\u63a5\u624b\u52a8\u590d\u5236'};
  const closeDialog=dialog=>engine()?engine().closeDialog(dialog):dialog.close();
  const openDialog=dialog=>engine()?engine().openDialog(dialog):dialog.showModal();
  function makeDialog(className,label){const dialog=document.createElement('dialog');dialog.className=className;dialog.setAttribute('aria-label',label);dialog.addEventListener('cancel',event=>{event.preventDefault();closeDialog(dialog);});document.body.append(dialog);return dialog;}
  function button(label,text){const node=document.createElement('button');node.type='button';node.className='icon-button';node.setAttribute('aria-label',label);node.title=label;node.textContent=text;return node;}
  if(root.dataset.imageLightbox==='1'&&typeof HTMLDialogElement!=='undefined'&&HTMLDialogElement.prototype.showModal){
    const images=[...document.querySelectorAll('.article-cover,[data-reading-body] img')].filter(n=>n.currentSrc||n.src);
    if(images.length){
      let dialog,index=0,returnTo=null,large,counter,error,prev,next,stage;
      function render(){const source=images[index];large.src=source.currentSrc||source.src;large.alt=source.alt||document.querySelector('.article-title')?.textContent||'';large.classList.remove('zoomed');stage.scrollTo(0,0);error.hidden=true;large.hidden=false;counter.textContent=(index+1)+' / '+images.length;prev.disabled=next.disabled=images.length<2;}
      function show(i,from){index=i;returnTo=from;
        if(!dialog){dialog=makeDialog('image-lightbox',words.view);const bar=document.createElement('div');bar.className='lightbox-toolbar';counter=document.createElement('span');counter.className='lightbox-counter';counter.setAttribute('aria-live','polite');prev=button(words.prev,'\u2190');next=button(words.next,'\u2192');const zoom=button(words.zoom,'\u2295'),close=button(words.close,'\u00d7');bar.append(counter,prev,next,zoom,close);stage=document.createElement('div');stage.className='lightbox-stage';stage.tabIndex=0;large=document.createElement('img');large.decoding='async';error=document.createElement('p');error.textContent=words.failed;error.setAttribute('role','status');stage.append(large,error);dialog.append(bar,stage);
          prev.addEventListener('click',()=>{index=(index+images.length-1)%images.length;render();});next.addEventListener('click',()=>{index=(index+1)%images.length;render();});zoom.addEventListener('click',()=>{large.classList.toggle('zoomed');zoom.setAttribute('aria-pressed',String(large.classList.contains('zoomed')));});close.addEventListener('click',()=>closeDialog(dialog));large.addEventListener('error',()=>{large.hidden=true;error.hidden=false;});dialog.addEventListener('close',()=>returnTo?.focus());dialog.addEventListener('keydown',event=>{if(event.key==='ArrowRight'||event.key==='ArrowLeft'){event.preventDefault();index=(index+(event.key==='ArrowRight'?1:-1)+images.length)%images.length;render();}});
        }
        render();openDialog(dialog);
      }
      images.forEach((image,i)=>{if(image.closest('a,button'))return;const trigger=document.createElement('button');trigger.type='button';trigger.className='lightbox-trigger';trigger.setAttribute('aria-label',words.view);image.replaceWith(trigger);trigger.append(image);trigger.addEventListener('click',()=>show(i,trigger));});
    }
  }
  document.querySelectorAll('[data-native-share]').forEach(trigger=>trigger.addEventListener('click',async()=>{
    const url=trigger.dataset.nativeShare,title=document.querySelector('.article-title')?.textContent||document.title;
    if(navigator.share){try{await navigator.share({title,url});return;}catch(error){if(error.name==='AbortError')return;}}
    try{await navigator.clipboard.writeText(url);trigger.dataset.shared='1';trigger.setAttribute('aria-label',words.copied);window.CYUI?.toast?.(words.copied);return;}catch(_){}
    if(typeof HTMLDialogElement==='undefined'||!HTMLDialogElement.prototype.showModal){window.prompt(words.manual,url);return;}
    const dialog=makeDialog('share-dialog panel',words.share),heading=document.createElement('h2'),input=document.createElement('input'),status=document.createElement('p'),close=button(words.close,'\u00d7');heading.textContent=words.share;input.type='text';input.readOnly=true;input.value=url;input.setAttribute('aria-label',words.share);status.textContent=words.manual;dialog.append(close,heading,input,status);close.addEventListener('click',()=>closeDialog(dialog));dialog.addEventListener('close',()=>{dialog.remove();trigger.focus();});openDialog(dialog);input.focus();input.select();
  }));
})();
