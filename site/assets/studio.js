/* Chengyu Studio: dependency-free progressive enhancement, original implementation. */
(() => {
  'use strict';
  const root = document.documentElement;
  if (root.dataset.adaptive === '1' && ((navigator.connection && navigator.connection.saveData) || (navigator.deviceMemory && navigator.deviceMemory <= 2) || (navigator.hardwareConcurrency && navigator.hardwareConcurrency <= 2))) root.dataset.economy = '1';
  document.addEventListener('visibilitychange', () => { root.dataset.paused = document.hidden ? '1' : '0'; });
  document.querySelectorAll('[data-variant-select]').forEach(select => select.addEventListener('change', () => {
    const output = select.closest('.variant-picker').querySelector('[data-variant-price]');
    output.textContent = select.selectedOptions[0]?.dataset.price || '';
  }));
  document.querySelectorAll('[data-reply-id]').forEach(button => button.addEventListener('click', () => {
    const form = document.querySelector('.comment-form');
    if (!form) return;
    form.querySelector('[name=parent_id]').value = button.dataset.replyId;
    form.querySelector('[name=body]').focus();
    form.scrollIntoView({block:'center', behavior:matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
  }));
  const source = document.querySelector('[data-layout-document]');
  if (!source) return;
  const form = source.form, list = document.querySelector('[data-block-list]'), error = document.querySelector('[data-studio-error]');
  const labels = JSON.parse(document.querySelector('#studio-i18n').textContent);
  const types=[...document.querySelectorAll('[data-block-add]')].map(button=>button.dataset.blockAdd);
  const choices = {
    audience:['all','guest','member','vip'],page_slot:Array.from({length:12},(_,i)=>'page'+(i+1)),
    route:JSON.parse(source.dataset.layoutRoutes),
    kind:['article','thread','product','all'], align:['left','center'], tone:['plain','glass','accent'],
    device:['all','desktop','mobile'], animation:['none','fade','rise']
  };
  let documentModel, dragIndex = null;
  const fail = message => { error.textContent = message; error.hidden = false; };
  const safeModel = value => {
    if (!value || value.format !== 'chengyu-layout' || value.version !== 1 || !Array.isArray(value.blocks) || value.blocks.length > 24) throw new Error(labels.importError);
    const ids = new Set();
    value.blocks.forEach(block => {
      if (!block || !types.includes(block.type) || typeof block.id !== 'string' || !/^[a-zA-Z][a-zA-Z0-9_-]{0,39}$/.test(block.id) || ids.has(block.id)) throw new Error(labels.importError);
      ids.add(block.id);
      for (const key of ['title','text','button']) if (key in block && typeof block[key] !== 'string') throw new Error(labels.importError);
      if('autoplay' in block && typeof block.autoplay!=='boolean')throw new Error(labels.importError);
      if('enabled' in block && typeof block.enabled!=='boolean')throw new Error(labels.importError);
      if('items' in block && (!Array.isArray(block.items) || block.items.length>12 || block.items.some(entry=>!entry || typeof entry!=='object' || Array.isArray(entry) || typeof entry.title!=='string' || ['text','icon','url'].some(key=>key in entry && typeof entry[key]!=='string'))))throw new Error(labels.importError);
    });
    return value;
  };
  try { documentModel = safeModel(JSON.parse(source.value)); } catch (_) { fail(labels.importError); return; }
  const uid = () => 'b' + (globalThis.crypto?.randomUUID ? crypto.randomUUID().replaceAll('-','') : Date.now().toString(36) + Math.random().toString(36).slice(2));
  const makeBlock = (type, overrides={}) => Object.assign({id:uid(),type,enabled:true,title:labels.blockTitle,text:'',button:'',route:'articles',kind:'article',category:0,collection:0,size:3,align:'left',tone:'plain',device:'all',animation:'fade',media_id:0,columns:3,background_id:0,autoplay:false,interval:6,items:[],audience:'all',min_vip_tier:1,page_slot:'page1'},overrides);
  const sync = () => { source.value = JSON.stringify(documentModel, null, 2); document.querySelector('[data-block-count]').textContent = documentModel.blocks.length + ' / 24'; error.hidden = true; };
  const move = (from,to) => {
    if (to < 0 || to >= documentModel.blocks.length) return;
    const [block] = documentModel.blocks.splice(from,1); documentModel.blocks.splice(to,0,block); render();live.textContent='\u5df2\u79fb\u81f3\u4f4d\u7f6e '+(to+1)+' / '+documentModel.blocks.length;
  };
  const control = (label, onClick, text, className='') => {
    const button = document.createElement('button'); button.type='button';button.className='studio-control '+className;button.textContent=text;button.title=label;button.setAttribute('aria-label',label);button.addEventListener('click',onClick);return button;
  };
  const add = blocks => {
    if (documentModel.blocks.length + blocks.length > 24) {fail(labels.limit);return;}
    documentModel.blocks.push(...blocks);render();
  };
  const live=document.createElement('p');live.className='sr-only';live.setAttribute('role','status');live.setAttribute('aria-live','polite');list.after(live);
  list.addEventListener('keydown',event=>{if(!event.altKey||!['ArrowUp','ArrowDown'].includes(event.key))return;const card=event.target.closest('[data-block-id]');if(!card)return;event.preventDefault();const index=Number(card.dataset.index);move(index,index+(event.key==='ArrowUp'?-1:1));});
  function render() {
    const oldCards=[...list.querySelectorAll('[data-block-id]')],expanded=new Map(oldCards.map(n=>[n.dataset.blockId,n.querySelector('details').open]));
    const active=document.activeElement,owner=active?.closest('[data-block-id]');
    const focus=owner&&list.contains(owner)?{id:owner.dataset.blockId,index:[...owner.querySelectorAll('button,input,select,textarea,summary')].indexOf(active)}:null;
    const update=()=>{
    list.replaceChildren();
    documentModel.blocks.forEach((block,index) => {
      const card=document.createElement('article');card.className='studio-block panel';card.dataset.index=String(index);card.dataset.blockId=block.id;card.dataset.layoutKey=block.id;
      const header=document.createElement('div');header.className='studio-block-header';
      const handle=control(labels.moveUp,()=>{},'::','drag-handle');handle.draggable=true;handle.setAttribute('aria-keyshortcuts','Alt+ArrowUp Alt+ArrowDown');
      handle.addEventListener('dragstart',e=>{dragIndex=index;e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',String(index));card.classList.add('is-dragging');});
      handle.addEventListener('dragend',()=>{dragIndex=null;card.classList.remove('is-dragging');});
      card.addEventListener('dragover',e=>{if(dragIndex!==null){e.preventDefault();e.dataTransfer.dropEffect='move';}});
      card.addEventListener('drop',e=>{e.preventDefault();if(dragIndex!==null){move(dragIndex,index);dragIndex=null;}});
      const title=document.createElement('strong');title.className='grow';title.textContent=String(index+1).padStart(2,'0')+' / '+(block.title || block.type);
      header.append(handle,title,control(labels.moveUp,()=>move(index,index-1),'\u2191'),control(labels.moveDown,()=>move(index,index+1),'\u2193'),control(labels.duplicate,()=>add([Object.assign(JSON.parse(JSON.stringify(block)),{id:uid()})]),'+'),control(labels.remove,()=>{documentModel.blocks.splice(index,1);render();},'\u00d7'));
      const badge=document.createElement('span');badge.className='studio-block-type';badge.textContent=labels[block.type]||block.type;
      const preview=document.createElement('div');preview.className='studio-mini-preview';preview.textContent=block.text || labels[block.type] || block.type;preview.setAttribute('aria-hidden','true');
      const fields=document.createElement('div');fields.className='studio-fields';
      const fieldNames=['title','text','button','route','tone','align','device','animation','audience','min_vip_tier','page_slot','background_id'];
      if(block.type==='slider')fieldNames.push('autoplay','interval');
      if(block.type==='media')fieldNames.push('media_id');
      if(['features','gallery','categories','plans','creators'].includes(block.type))fieldNames.push('columns');
      if (['content','collection','categories','titles'].includes(block.type)) fieldNames.push('kind','category','size');
      if (block.type==='collection') fieldNames.push('collection');
      if (['spacer','links','plans','noticeboard','creators'].includes(block.type)) fieldNames.push('size');
      fieldNames.forEach(name=>{
        const wrap=document.createElement('label');wrap.className='field'+(name==='text'?' field-wide':'');
        const label=document.createElement('span');label.textContent=labels[name];
        let input;
        if(name==='autoplay'){input=document.createElement('input');input.type='checkbox';input.checked=!!block.autoplay;}
        else if(choices[name]) {input=document.createElement('select');choices[name].forEach(value=>{const option=document.createElement('option');option.value=value;option.textContent=labels[value]||value;input.append(option);});}
        else if(name==='text') {input=document.createElement('textarea');input.rows=2;input.maxLength=5000;}
        else {input=document.createElement('input');input.type=['size','category','collection','media_id','min_vip_tier','columns','background_id','interval'].includes(name)?'number':'text';if(input.type==='number'){input.min=['size','columns','min_vip_tier'].includes(name)?'1':'0';if(name==='size')input.max='12';if(name==='columns')input.max='4';if(name==='interval'){input.min='3';input.max='20';}}else{input.maxLength=name==='title'?120:80;}}
        input.value=block[name] ?? (choices[name]?.[0] || '');
        input.addEventListener('input',()=>{block[name]=input.type==='checkbox'?input.checked:input.type==='number'?Number(input.value):input.value;title.textContent=String(index+1).padStart(2,'0')+' / '+(block.title||block.type);preview.textContent=block.text||block.type;sync();});
        wrap.append(label,input);fields.append(wrap);
      });
      if(JSON.parse(source.dataset.layoutEntryTypes).includes(block.type)){
        const section=document.createElement('section');section.className='studio-entry-editor field-wide';
        const caption=document.createElement('strong');caption.textContent=labels.entries||'Entries';section.append(caption);
        if(!Array.isArray(block.items))block.items=[];
        const entries=document.createElement('div');section.append(entries);
        function paintEntries(){entries.replaceChildren();block.items.forEach((entry,entryIndex)=>{
          const box=document.createElement('div');box.className='studio-entry panel';
          ['title','text',...(['gallery','slider'].includes(block.type)?['media_id']:[]),...(block.type==='slider'?['mobile_media_id']:[]),...(['features','buttons'].includes(block.type)?['icon']:[]),...(['features','tabs','gallery','slider','buttons'].includes(block.type)?['url']:[])].forEach(name=>{
            const wrap=document.createElement('label');wrap.className='field';const label=document.createElement('span');label.textContent=labels[name]||name;
            let input;if(name==='icon'){input=document.createElement('select');JSON.parse(source.dataset.layoutSymbols).forEach(value=>{const option=document.createElement('option');option.value=value;option.textContent=labels[value]||value;input.append(option);});}else{input=document.createElement(name==='text'?'textarea':'input');if(name==='text'){input.rows=3;input.maxLength=2000;}else{input.type=['media_id','mobile_media_id'].includes(name)?'number':name==='url'?'url':'text';input.maxLength=name==='url'?1000:120;if(['media_id','mobile_media_id'].includes(name))input.min='0';}}
            input.value=entry[name]??(name==='icon'?'sparkles':'');input.addEventListener('input',()=>{entry[name]=['media_id','mobile_media_id'].includes(name)?Number(input.value):input.value;sync();});wrap.append(label,input);box.append(wrap);
          });
          const commands=document.createElement('div');commands.className='row';
          commands.append(control(labels.moveUp,()=>{if(entryIndex>0){[block.items[entryIndex-1],block.items[entryIndex]]=[block.items[entryIndex],block.items[entryIndex-1]];paintEntries();sync();}},'↑'),control(labels.remove,()=>{block.items.splice(entryIndex,1);paintEntries();sync();},'×'));
          box.append(commands);entries.append(box);
        });}
        paintEntries();const append=control(labels.addEntry||'Add entry',()=>{if(block.items.length>=12){fail(labels.entryLimit||'Maximum 12 entries.');return;}block.items.push({title:labels.blockTitle||'New entry',text:'',icon:'sparkles',media_id:0,url:''});paintEntries();sync();},'+ '+(labels.addEntry||'Add entry'));section.append(append);fields.append(section);
      }
      const enabled=document.createElement('label');enabled.className='checkbox';const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.checked=block.enabled!==false;checkbox.addEventListener('change',()=>{block.enabled=checkbox.checked;sync();});enabled.append(checkbox,document.createTextNode(labels.enabled));
      const details=document.createElement('details');details.open=expanded.has(block.id)?expanded.get(block.id):index===documentModel.blocks.length-1;const summary=document.createElement('summary');summary.textContent=labels.title+' / '+labels.text;details.append(summary,fields,enabled);
      card.append(header,badge,preview,details);list.append(card);
    });
    document.querySelector('[data-studio-empty]').hidden=!!documentModel.blocks.length;sync();
    };
    if(window.CYLayoutMotion)CYLayoutMotion.change(list,update);else update();
    if(focus){const card=[...list.children].find(n=>n.dataset.blockId===focus.id);const target=card?.querySelectorAll('button,input,select,textarea,summary')[focus.index];if(target)target.focus({preventScroll:true});else document.querySelector('[data-block-add]')?.focus({preventScroll:true});}
  }
  document.querySelectorAll('[data-block-add]').forEach(button=>button.addEventListener('click',()=>add([makeBlock(button.dataset.blockAdd)])));
  document.querySelector('[data-layout-template-add]').addEventListener('click',()=>{
    const template=document.querySelector('[data-layout-template]').value;
    if(template==='editorial')add([makeBlock('heading',{title:labels.templateEditorial,align:'center'}),makeBlock('content',{title:labels.templateStories,size:3})]);
    if(template==='resource')add([makeBlock('cta',{title:labels.templateResources,text:labels.templateResourceText,route:'shop',button:labels.explore,tone:'accent'}),makeBlock('content',{title:labels.templateResources,kind:'product',size:3})]);
    if(template==='community')add([makeBlock('heading',{title:labels.templateCommunity,text:labels.templateCommunityText}),makeBlock('content',{title:labels.templateConversations,kind:'thread',size:3})]);
    if(template==='creator')add([makeBlock('hero',{title:labels.templateEditorial,text:labels.templateResourceText,button:labels.explore,route:'articles',tone:'glass'}),makeBlock('features',{title:labels.features,items:[{title:labels.templateStories,text:'',icon:'book',media_id:0,url:''},{title:labels.templateResources,text:'',icon:'code',media_id:0,url:''}]}),makeBlock('creators',{title:labels.creators,size:3}),makeBlock('titles',{title:labels.templateStories,size:6})]);
    if(template==='product')add([makeBlock('hero',{title:labels.templateResources,text:labels.templateResourceText,button:labels.explore,route:'shop',tone:'accent'}),makeBlock('features',{title:labels.features,items:[{title:labels.templateResources,text:'',icon:'box',media_id:0,url:''},{title:labels.templateCommunity,text:'',icon:'users',media_id:0,url:''}]}),makeBlock('tabs',{title:labels.tabs,items:[{title:labels.templateResources,text:'',icon:'box',media_id:0,url:''},{title:labels.templateStories,text:'',icon:'book',media_id:0,url:''}]}),makeBlock('content',{title:labels.templateResources,kind:'product',size:3}),makeBlock('plans',{title:labels.plans,size:3})]);
  });
  document.querySelector('[data-layout-import]').addEventListener('change',async event=>{
    const file=event.target.files[0];if(!file)return;
    if(file.size>100000){fail(labels.importError);event.target.value='';return;}
    try{const incoming=safeModel(JSON.parse(await file.text()));documentModel=incoming;render();}catch(_){fail(labels.importError);}event.target.value='';
  });
  document.querySelector('[data-layout-export]').addEventListener('click',()=>{
    sync();const blob=new Blob([source.value],{type:'application/json'}),objectURL=URL.createObjectURL(blob),anchor=document.createElement('a');anchor.href=objectURL;anchor.download='chengyu-layout-'+form.querySelector('[name=slot]').value+'.json';anchor.click();setTimeout(()=>URL.revokeObjectURL(objectURL),1000);
  });
  source.addEventListener('change',()=>{try{documentModel=safeModel(JSON.parse(source.value));render();}catch(_){fail(labels.importError);}});
  form.addEventListener('submit',event=>{try{documentModel=safeModel(JSON.parse(source.value));sync();}catch(_){event.preventDefault();fail(labels.importError);}});
  render();
})();
