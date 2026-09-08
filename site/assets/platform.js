/* Original progressive enhancement: all writes also work as ordinary CSRF-protected forms. */
(() => {
  'use strict';
  const lang = (key) => {
    const words = { running:['Indexing','\u7d22\u5f15\u6784\u5efa\u4e2d'], ready:['Index ready','\u7d22\u5f15\u5df2\u5c31\u7eea'], paused:['Paused. The next request resumes from the saved cursor.','\u5df2\u6682\u505c\uff0c\u4e0b\u6b21\u5c06\u4ece\u5df2\u4fdd\u5b58\u7684\u4f4d\u7f6e\u7ee7\u7eed\u3002'], unsaved:['Playback position copied. Save to keep it.','\u5df2\u8bb0\u5f55\u64ad\u653e\u4f4d\u7f6e\uff0c\u8bf7\u4fdd\u5b58\u8fdb\u5ea6\u3002'], failed:['Request failed. Review the message or reload before retrying.','\u8bf7\u6c42\u5931\u8d25\uff0c\u8bf7\u67e5\u770b\u63d0\u793a\u6216\u91cd\u65b0\u52a0\u8f7d\u3002'] };
    return (words[key] || [key,key])[document.documentElement.lang.startsWith('zh') ? 1 : 0];
  };
  async function send(form, fields, signal) {
    const endpoint = new URL(form.action, location.href);
    if (endpoint.origin !== location.origin) throw new Error(lang('failed'));
    const response = await fetch(endpoint.href, {method:'POST', body:fields, credentials:'same-origin', headers:{Accept:'application/json'}, signal});
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.message || lang('failed'));
    return data;
  }
  document.querySelectorAll('.platform-job').forEach(form => {
    const start=form.querySelector('[data-index-run]'), stop=form.querySelector('[data-index-stop]'), status=form.querySelector('[data-index-status]');
    if (!start || !stop || !status) return;
    let running=false, controller=null;
    stop.addEventListener('click', () => {running=false;if(controller)controller.abort();});
    start.addEventListener('click', async () => {
      if(running)return;running=true;start.disabled=true;stop.hidden=false;
      const submits=Array.from(form.querySelectorAll('button[type=submit]'));submits.forEach(x=>x.disabled=true);
      try {
        for(let batch=0;running && batch<100;batch++) {
          controller=new AbortController();const fields=new FormData(form);fields.delete('reset');
          const data=await send(form,fields,controller.signal);const index=data.index;
          status.textContent=lang(index.completed ? 'ready' : 'running')+' / '+index.documents+' / #'+index.cursor_id;
          if(index.completed){running=false;break;}
          await new Promise(resolve=>setTimeout(resolve,120));
        }
        if(running){running=false;status.textContent=lang('paused');}
      } catch(error) {status.textContent=error.name==='AbortError'?lang('paused'):(error.message||lang('failed'));}
      finally {running=false;controller=null;start.disabled=false;stop.hidden=true;submits.forEach(x=>x.disabled=false);}
    });
  });
  document.querySelectorAll('.remote-job').forEach(form => {
    const start=form.querySelector('[data-remote-run]'), stop=form.querySelector('[data-remote-stop]'), output=form.querySelector('[data-remote-status]');
    if(!start || !stop || !output)return;
    let running=false, controller=null;
    const message=(en,zh)=>document.documentElement.lang.startsWith('zh')?zh:en;
    const pause=()=>{running=false;if(controller)controller.abort();};
    stop.addEventListener('click',pause);
    document.addEventListener('visibilitychange',()=>{if(document.hidden)pause();});
    start.addEventListener('click',async()=>{
      if(running || document.hidden)return;
      running=true;start.disabled=true;stop.hidden=false;form.setAttribute('aria-busy','true');
      const submits=Array.from(form.querySelectorAll('button[type=submit]'));submits.forEach(b=>b.disabled=true);
      try{
        for(let n=0;running && n<12;n++){
          controller=new AbortController();const timeout=setTimeout(()=>controller && controller.abort(),15000);let data;
          try{data=await send(form,new FormData(form),controller.signal);}finally{clearTimeout(timeout);}
          const index=data.index;
          if(!index || !Number.isInteger(index.queued) || !Number.isInteger(index.waiting) || !Array.isArray(data.tasks))throw new Error(lang('failed'));
          output.textContent=message('Queued: ','\u961f\u5217\uff1a')+index.queued+message(' / awaiting confirmation: ',' / \u5f85\u786e\u8ba4\uff1a')+index.waiting;
          if(index.queued===0 || data.tasks.length===0 || data.tasks.some(t=>t.status==='retry'))break;
          await new Promise(resolve=>setTimeout(resolve,180));
        }
        output.textContent+=' '+message('Session ended. Pending tasks remain saved for a later request.','\u672c\u6b21\u4f1a\u8bdd\u5df2\u7ed3\u675f\uff0c\u672a\u5b8c\u6210\u4efb\u52a1\u5df2\u4fdd\u7559\u3002');
      }catch(error){output.textContent=error.name==='AbortError'?message('Stopped. Check saved progress before retrying.','\u5df2\u505c\u6b62\uff0c\u8bf7\u5148\u68c0\u67e5\u5df2\u4fdd\u5b58\u7684\u8fdb\u5ea6\u3002'):(error.message||lang('failed'));}
      finally{running=false;controller=null;start.disabled=false;stop.hidden=true;form.removeAttribute('aria-busy');submits.forEach(b=>b.disabled=false);}
    });
  });
  document.querySelectorAll('[data-reply-template]').forEach(select=>select.addEventListener('change',()=>{
    const body=select.form && select.form.querySelector('[data-support-body]');
    if(body && select.value){body.value += (body.value.trim() ? '\n\n' : '') + select.value;body.dispatchEvent(new Event('input',{bubbles:true}));body.focus();select.value='';}
  }));
  const player=document.querySelector('[data-lesson-player]'),form=document.querySelector('.learning-progress-form');
  if(player){player.addEventListener('loadedmetadata',()=>{const start=Number(player.dataset.start)||0;if(Number.isFinite(player.duration)&&start>0 && start<player.duration)player.currentTime=start;},{once:true});}
  if(form){
    const status=form.querySelector('[data-learning-status]'),position=form.querySelector('[data-learning-position]');let saving=false;
    if(player){player.addEventListener('pause',()=>{position.value=String(Math.min(604800,Math.max(0,Math.floor(player.currentTime||0))));status.textContent=lang('unsaved');});}
    form.addEventListener('submit',async event=>{
      event.preventDefault();if(saving || !form.reportValidity())return;saving=true;form.setAttribute('aria-busy','true');
      if(player && !player.paused)position.value=String(Math.min(604800,Math.floor(player.currentTime||0)));
      try{const data=await send(form,new FormData(form));form.elements.revision.value=data.progress.revision;status.textContent=data.message;}
      catch(error){status.textContent=error.message||lang('failed');}
      finally{saving=false;form.removeAttribute('aria-busy');}
    });
  }
})();
