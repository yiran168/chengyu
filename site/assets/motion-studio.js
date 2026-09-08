/* The inspector edits the same validated configuration that renders public pages. */
(() => {
  'use strict';
  const form=document.querySelector('.motion-studio-form');if(!form || !window.CYMotion)return;
  const presetRoot=document.querySelector('[data-motion-presets]');let presets={};try{presets=JSON.parse(presetRoot.dataset.motionPresets);}catch(_){}
  const controls=[...form.querySelectorAll('input[name]:not([type=hidden]),select[name]')];
  const read=()=>Object.fromEntries(controls.map(n=>[n.name,n.type==='checkbox'?n.checked:n.type==='range'?Number(n.value):n.value]));
  let animations=[];
  function inspector(){
    const config=CYMotion.config(),curve=CYMotion.curve(),[x1,y1,x2,y2]=curve,p1=[x1*100,100-y1*100],p2=[x2*100,100-y2*100];
    form.querySelector('[data-curve-line]').setAttribute('d',`M0 100C${p1.join(' ')} ${p2.join(' ')} 100 0`);
    form.querySelector('[data-curve-guides]').setAttribute('d',`M0 100L${p1.join(' ')}M100 0L${p2.join(' ')}`);
    [p1,p2].forEach((point,i)=>{const node=form.querySelector('[data-curve-point="'+(i+1)+'"]');node.setAttribute('cx',point[0]);node.setAttribute('cy',point[1]);});
    form.querySelector('[data-curve-code]').textContent='cubic-bezier('+curve.join(', ')+')';form.querySelector('[data-curve-duration]').textContent=config.motion_duration+' ms';
    controls.forEach(node=>{const output=form.querySelector('[data-motion-value="'+node.name+'"]');if(output)output.textContent=node.value;});
    if(window.CYSpring){const sample=CYSpring.sample(CYMotion.physics()),duration=sample.duration/1000;
      form.querySelector('[data-spring-line]').setAttribute('d',sample.points.map((p,i)=>(i?'L':'M')+(p.time/duration*320).toFixed(2)+' '+(110-p.value*72).toFixed(2)).join(' '));
      form.querySelector('[data-spring-ratio]').textContent='\u03b6 '+sample.ratio.toFixed(2);form.querySelector('[data-spring-time]').textContent=(sample.duration/1000).toFixed(2)+' s';}
    const reduced=matchMedia('(prefers-reduced-motion:reduce)').matches,economy=document.documentElement.dataset.economy==='1';
    form.querySelector('[data-motion-status]').textContent=reduced?'\u7cfb\u7edf\u5df2\u8981\u6c42\u51cf\u5c11\u52a8\u6001\u6548\u679c\uff0c\u5f53\u524d\u4fdd\u6301\u9759\u6001\u3002':!CYMotion.enabled()?'\u52a8\u6548\u5df2\u5173\u95ed\uff0c\u6240\u6709\u4e1a\u52a1\u529f\u80fd\u4fdd\u6301\u53ef\u7528\u3002':economy?'\u4f4e\u8d44\u6e90\u6a21\u5f0f\uff1a\u51cf\u5c11\u6a21\u7cca\u4e0e\u6307\u9488\u7279\u6548\u3002':'\u52a8\u6548\u5df2\u542f\u7528\uff1b\u7cfb\u7edf\u51cf\u5c11\u52a8\u6001\u504f\u597d\u59cb\u7ec8\u4f18\u5148\u3002';
    form.querySelectorAll('[name^=curve_]').forEach(node=>node.closest('.motion-field').classList.toggle('inactive-control',config.motion_curve!=='custom'));
  }
  function replaySpring(){if(!window.CYSpring||!CYMotion.enabled())return;const node=form.querySelector('[data-spring-runner]'),sample=CYSpring.sample(CYMotion.physics()),width=Math.max(0,node.parentElement.clientWidth-node.offsetWidth);animations.push(CYMotion.animate(node,sample.points.map(p=>({transform:'translateX('+p.value*width+'px)',offset:p.time/(sample.duration/1000)})),{duration:sample.duration,easing:'linear',fill:'forwards'}));}
  function update(){CYMotion.configure(read());document.querySelector('[data-motion-dirty]').textContent='\u672a\u4fdd\u5b58\u7684\u9884\u89c8';}
  function replay(){
    animations.forEach(a=>a?.cancel());animations=[];if(!CYMotion.enabled())return;
    replaySpring();const config=CYMotion.config();animations.push(CYMotion.animate(form.querySelector('[data-motion-demo]'),[{opacity:0,transform:'translateY('+config.motion_distance+'px) scale(.975)'},{opacity:1,transform:'none'}]));
    form.querySelectorAll('.motion-stagger-demo i').forEach((node,i)=>animations.push(CYMotion.animate(node,[{opacity:0,transform:'translateY(14px)'},{opacity:1,transform:'none'}],{delay:i*config.motion_stagger,fill:'backwards'})));
    const runner=form.querySelector('[data-curve-runner]'),width=runner.parentElement.clientWidth-runner.offsetWidth;
    animations.push(CYMotion.animate(runner,[{transform:'translateX(0)'},{transform:'translateX('+Math.max(0,width)+'px)'}],{fill:'forwards'}));
  }
  controls.forEach(node=>node.addEventListener('input',update));
  presetRoot.querySelectorAll('[data-motion-preset]').forEach(button=>button.addEventListener('click',()=>{
    const patch=presets[button.dataset.motionPreset];if(!patch)return;
    controls.forEach(node=>{if(!(node.name in patch))return;if(node.type==='checkbox')node.checked=Boolean(patch[node.name]);else node.value=String(patch[node.name]);});
    presetRoot.querySelectorAll('button').forEach(b=>b.setAttribute('aria-pressed',b===button?'true':'false'));update();replay();
  }));
  form.querySelectorAll('[data-motion-replay]').forEach(button=>button.addEventListener('click',replay));
  form.querySelector('[data-spring-replay]')?.addEventListener('click',()=>{animations.forEach(a=>a?.cancel());animations=[];replaySpring();});
  document.addEventListener('cy:motion',inspector);inspector();
})();
