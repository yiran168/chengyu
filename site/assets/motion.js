/* Chengyu Motion - one progressive, event-driven engine; no animation framework. */
(() => {
  'use strict';
  const root = document.documentElement, reduced = matchMedia('(prefers-reduced-motion: reduce)'), fine = matchMedia('(hover:hover) and (pointer:fine)');
  let config = {}; try { config = JSON.parse(root.dataset.motionSettings || '{}'); } catch (_) {}
  // The PHP contract supplies presets; no independently maintained curve table.
  let curves={};try {curves=JSON.parse(root.dataset.motionCurves || '{}');}catch(_){}
  const defaultCurve=curves.gentle || [0,0,1,1];
  const bool = key => config[key] === true || config[key] === 1 || config[key] === '1';
  const number = (key, fallback, min, max) => Math.max(min, Math.min(max, Number.isFinite(Number(config[key])) ? Number(config[key]) : fallback));
  const economy = () => bool('motion_adaptive') && ((navigator.connection && navigator.connection.saveData) || (navigator.deviceMemory && navigator.deviceMemory <= 2) || (navigator.hardwareConcurrency && navigator.hardwareConcurrency <= 2));
  const enabled = () => bool('motion') && !reduced.matches;
  const interactive = () => enabled() && !economy() && fine.matches && !document.hidden;
  const easing = () => config.motion_curve === 'custom' ? [number('curve_x1',22,0,100),number('curve_y1',100,-100,200),number('curve_x2',36,0,100),number('curve_y2',100,-100,200)].map(v=>v/100) : curves[config.motion_curve] || defaultCurve;
  let observer, viewTransition, frame = 0, pointerTarget = null;
  const animated = new Set(), hovering = new Set();
  const listenMedia = (media, fn) => media.addEventListener ? media.addEventListener('change', fn) : media.addListener(fn);
  function resetPointer() {
    if(frame) cancelAnimationFrame(frame); frame=0; states.clear();lastTick=0;
    hovering.forEach(node=>{ ['--tilt-x','--tilt-y','--magnet-x','--magnet-y','--parallax-x','--parallax-y','--pointer-lift'].forEach(k=>node.style.removeProperty(k)); node.classList.remove('pointer-active','spring-active'); });
    hovering.clear(); pointerTarget=null;
  }
  function reveal() {
    if(observer) observer.disconnect();
    const nodes = [...document.querySelectorAll('.reveal')];
    nodes.forEach(n=>{n.classList.remove('cy-wait');n.style.removeProperty('--reveal-delay');});
    if(!enabled() || !bool('reveal') || number('motion_duration',480,0,1200)===0 || !('IntersectionObserver' in window)) { nodes.forEach(n=>n.classList.add('visible')); return; }
    observer = new IntersectionObserver(entries=>{
      let index=0;
      entries.forEach(entry=>{
        if(!entry.isIntersecting) return;
        const node=entry.target; node.style.setProperty('--reveal-delay', Math.min(index++ * number('motion_stagger',55,0,120),360)+'ms');
        node.classList.add('visible');node.classList.remove('cy-wait');observer.unobserve(node);
        // Remove a reveal-only delay before subsequent pointer interactions.
        setTimeout(()=>node.style.removeProperty('--reveal-delay'),number('motion_duration',480,0,1200)+420);
      });
    },{threshold:0.08,rootMargin:'0px 0px 32px 0px'});
    nodes.forEach(node=>{if(!node.classList.contains('visible')){node.classList.add('cy-wait');observer.observe(node);}});
  }
  function apply() {
    root.dataset.paused=document.hidden?'1':'0';root.dataset.motion=enabled()?'1':'0';root.dataset.economy=economy()?'1':'0';root.dataset.adaptive=bool('motion_adaptive')?'1':'0';
    ['ambient','grain','particles','reveal','ripple'].forEach(k=>root.dataset[k]=bool(k)?'1':'0');root.dataset.transition=bool('page_transition')?'1':'0';
    root.dataset.revealStyle=config.motion_reveal_style||'rise';root.dataset.curveName=config.motion_curve||'gentle';
    root.dataset.continuous=bool('motion_continuous')?'1':'0';root.dataset.sheen=bool('motion_sheen')?'1':'0';
    root.dataset.spotlight=bool('motion_spotlight')?'1':'0';root.dataset.tilt=bool('motion_tilt')?'1':'0';root.dataset.response=config.motion_response||'spring';root.dataset.feedback=bool('motion_feedback')?'1':'0';
    root.style.setProperty('--curve','cubic-bezier('+easing().join(',')+')');
    root.style.setProperty('--duration',number('motion_duration',480,0,1200)+'ms');root.style.setProperty('--lift',number('hover_lift',5,0,12)+'px');
    root.style.setProperty('--reveal-distance',number('motion_distance',18,0,40)+'px');
    root.style.setProperty('--stagger-step',number('motion_stagger',55,0,120)+'ms');
    resetPointer();
    if(!enabled()) { animated.forEach(a=>a.cancel()); animated.clear(); viewTransition?.skipTransition(); document.querySelectorAll('.ripple').forEach(n=>n.remove()); }
    reveal(); document.dispatchEvent(new CustomEvent('cy:motion',{detail:{enabled:enabled(),economy:economy(),reduced:reduced.matches,curve:easing(),config:{...config}}}));
  }
  function animate(node, keyframes, options={}) {
    if(!enabled() || !node.animate) return null;
    const animation=node.animate(keyframes,{duration:number('motion_duration',480,0,1200),easing:'cubic-bezier('+easing().join(',')+')',...options});
    animated.add(animation);animation.finished.then(()=>animated.delete(animation),()=>animated.delete(animation));return animation;
  }
  const states=new Map();let lastTick=0;
  const physics=()=>({stiffness:number('spring_stiffness',180,40,480),damping:number('spring_damping',24,8,60),mass:number('spring_mass',100,40,250)/100});
  function paint(node,state){
    const clamp=(n,limit)=>Math.max(-limit,Math.min(limit,n));
    const px=clamp(state.x.x,1.15),py=clamp(state.y.x,1.15),lift=clamp(state.h.x,1.1);
    node.style.setProperty('--pointer-lift',(-number('hover_lift',5,0,12)*lift)+'px');
    if(node.matches('[data-kinetic-card]')&&bool('motion_tilt')){const deg=number('motion_tilt_degrees',3,0,8);node.style.setProperty('--tilt-x',(-py*deg)+'deg');node.style.setProperty('--tilt-y',(px*deg)+'deg');}
    if(node.matches('.btn')&&bool('motion_magnetic')){const d=number('motion_magnetic_distance',4,0,12);node.style.setProperty('--magnet-x',px*d+'px');node.style.setProperty('--magnet-y',py*d+'px');}
    if(node.matches('.hero-art')&&bool('motion_parallax')){node.style.setProperty('--parallax-x',px*7+'px');node.style.setProperty('--parallax-y',py*7+'px');}
  }
  function tick(time){
    frame=0;if(!interactive()){resetPointer();return;}
    const dt=lastTick?Math.min(.05,(time-lastTick)/1000):1/60;lastTick=time;let moving=false;
    states.forEach((state,node)=>{
      const spring=config.motion_response==='spring'&&window.CYSpring;
      ['x','y','h'].forEach(axis=>{state[axis]=spring?CYSpring.advance(state[axis],state.target[axis],dt,physics()):{x:state.target[axis],v:0};});
      paint(node,state);
      const resting=['x','y','h'].every(axis=>Math.abs(state[axis].x-state.target[axis])<.001&&Math.abs(state[axis].v)<.002);
      if(resting){if(state.target.h===0){node.classList.remove('pointer-active','spring-active');hovering.delete(node);states.delete(node);}}else moving=true;
    });
    if(moving)frame=requestAnimationFrame(tick);else lastTick=0;
  }
  function schedule(){if(!frame){lastTick=0;frame=requestAnimationFrame(tick);}}
  function leavePointer(){if(pointerTarget&&states.has(pointerTarget)){states.get(pointerTarget).target={x:0,y:0,h:0};schedule();}pointerTarget=null;}
  document.querySelectorAll('.content-card,.quick-link,.featured-item,.motion-preview-card,.circle-card').forEach(n=>n.dataset.kineticCard='');
  document.addEventListener('pointermove',event=>{
    if(!interactive()||event.pointerType==='touch')return;
    const target=event.target.closest('.btn,[data-kinetic-card],.hero-art');
    if(target!==pointerTarget){leavePointer();pointerTarget=target;}if(!target)return;
    const r=target.getBoundingClientRect();if(!r.width||!r.height)return;
    const x=event.clientX-r.left,y=event.clientY-r.top;
    target.style.setProperty('--pointer-x',x+'px');target.style.setProperty('--pointer-y',y+'px');
    target.classList.add('pointer-active');target.classList.toggle('spring-active',config.motion_response==='spring');hovering.add(target);
    let state=states.get(target);if(!state){state={x:{x:0,v:0},y:{x:0,v:0},h:{x:0,v:0},target:{x:0,y:0,h:0}};states.set(target,state);}
    state.target={x:Math.max(-1,Math.min(1,(x/r.width-.5)*2)),y:Math.max(-1,Math.min(1,(y/r.height-.5)*2)),h:1};schedule();
  },{passive:true});
  document.addEventListener('pointerleave',leavePointer);window.addEventListener('blur',resetPointer);
  document.addEventListener('pointerdown',event=>{
    const button=event.target.closest('.btn');if(!button || button.disabled || !enabled() || !bool('ripple') || event.button!==0)return;
    const r=button.getBoundingClientRect(),ripple=document.createElement('span'),size=Math.max(r.width,r.height)*2;
    ripple.className='ripple';ripple.setAttribute('aria-hidden','true');ripple.style.cssText='width:'+size+'px;height:'+size+'px;left:'+(event.clientX-r.left-size/2)+'px;top:'+(event.clientY-r.top-size/2)+'px';button.append(ripple);setTimeout(()=>ripple.remove(),800);
  },{passive:true});
  async function transition(update, origin) {
    let applied=false;const once=()=>{if(!applied){applied=true;update();}};
    if(!enabled() || !document.startViewTransition || document.hidden) {once();return;}
    if(viewTransition)viewTransition.skipTransition();
    const style=config.motion_theme_transition||'crossfade';root.dataset.themeTransition=style;
    try {
      const tx=document.startViewTransition(once);viewTransition=tx;
      await tx.ready;
      if(style==='iris') {
        const x=origin?.clientX || innerWidth/2,y=origin?.clientY || Math.min(120,innerHeight/2),radius=Math.hypot(Math.max(x,innerWidth-x),Math.max(y,innerHeight-y));
        animate(root,{clipPath:['circle(0px at '+x+'px '+y+'px)','circle('+radius+'px at '+x+'px '+y+'px)']},{pseudoElement:'::view-transition-new(root)',duration:number('motion_duration',480,0,1200),fill:'both'});
      }
      await tx.finished;
      if(viewTransition===tx){viewTransition=null;delete root.dataset.themeTransition;}
    } catch(_) {once();}
  }
  const closing=new WeakMap();
  function openDialog(dialog) {
    if(!dialog || !dialog.showModal)return;
    const old=closing.get(dialog);if(old){old.cancel();closing.delete(dialog);}if(dialog.open)return;
    dialog.showModal();animate(dialog,[{opacity:0,transform:'translateY(12px) scale(.975)'},{opacity:1,transform:'none'}],{duration:Math.min(420,number('motion_duration',480,0,1200))});
  }
  function closeDialog(dialog) {
    if(!dialog || !dialog.open || closing.has(dialog))return;
    const animation=animate(dialog,[{opacity:1,transform:'none'},{opacity:0,transform:'translateY(8px) scale(.985)'}],{duration:Math.min(180,number('motion_duration',480,0,1200)),easing:'ease-in'});
    if(!animation){dialog.close();return;}closing.set(dialog,animation);
    animation.finished.then(()=>{if(closing.get(dialog)===animation){closing.delete(dialog);dialog.close();}},()=>{if(closing.get(dialog)===animation){closing.delete(dialog);dialog.close();}});
  }
  document.querySelectorAll('dialog').forEach(dialog=>dialog.addEventListener('cancel',event=>{event.preventDefault();closeDialog(dialog);}));
  document.addEventListener('visibilitychange',()=>{root.dataset.paused=document.hidden?'1':'0';if(document.hidden)resetPointer();});
  if('IntersectionObserver' in window) {
    const heroObserver=new IntersectionObserver(entries=>entries.forEach(entry=>entry.target.dataset.offscreen=entry.isIntersecting?'0':'1'));
    document.querySelectorAll('.hero-art,.motion-stage').forEach(n=>heroObserver.observe(n));
  }
  listenMedia(reduced,apply);listenMedia(fine,apply);navigator.connection?.addEventListener?.('change',apply);
  window.CYMotion={duration:(cap=1200)=>Math.min(cap,number('motion_duration',480,0,1200)),physics,pointerStatus:()=>({tracked:states.size,scheduled:frame!==0}),enabled,animate,transition,openDialog,closeDialog,curve:easing,configure:patch=>{config={...config,...patch};apply();},config:()=>({...config}),replay:()=>{document.querySelectorAll('.reveal').forEach(n=>n.classList.remove('visible'));reveal();}};
  window.addEventListener('pagereveal',event=>{if(event.viewTransition&&!enabled())event.viewTransition.skipTransition();});
  apply();
})();
