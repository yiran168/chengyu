/* Native scroll-snap remains usable without scripting. Original Chengyu widget. */
(() => {
  'use strict';
  const root=document.documentElement, reduced=matchMedia('(prefers-reduced-motion: reduce)'), widgets=[];
  const motion=()=>!reduced.matches && root.dataset.motion!=='0' && parseFloat(getComputedStyle(root).getPropertyValue('--duration'))>0;
  document.querySelectorAll('[data-carousel]').forEach(element=>{
    const track=element.querySelector('.carousel-track'), slides=[...track.children], controls=element.querySelector('.carousel-controls');
    if(slides.length<2)return;
    const counter=element.querySelector('[data-carousel-count]'),pause=element.querySelector('[data-carousel-pause]');
    let current=0,timer=0,paused=false,hovered=false,inView=false,frame=0;
    controls.hidden=false;
    const index=()=>slides.reduce((best,slide,i)=>Math.abs(slide.offsetLeft-track.scrollLeft)<Math.abs(slides[best].offsetLeft-track.scrollLeft)?i:best,0);
    const clear=()=>{clearTimeout(timer);timer=0;};
    const schedule=()=>{clear();if(element.dataset.autoplay==='1' && !paused && !hovered && inView && !document.hidden && !element.contains(document.activeElement) && motion())timer=setTimeout(()=>move(current+1),Number(element.dataset.interval)||6000);};
    const move=next=>{current=(next+slides.length)%slides.length;track.scrollTo({left:slides[current].offsetLeft,behavior:motion()?'smooth':'instant'});schedule();};
    element.querySelector('[data-carousel-prev]').addEventListener('click',()=>move(current-1));
    element.querySelector('[data-carousel-next]').addEventListener('click',()=>move(current+1));
    if(pause)pause.addEventListener('click',()=>{paused=!paused;pause.textContent=pause.dataset[paused?'playLabel':'pauseLabel'];schedule();});
    track.addEventListener('keydown',event=>{if(event.target!==track || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();move(event.key==='Home'?0:event.key==='End'?slides.length-1:current+(event.key==='ArrowRight'?1:-1));});
    track.addEventListener('scroll',()=>{if(frame)return;frame=requestAnimationFrame(()=>{frame=0;current=index();counter.textContent=(current+1)+' / '+slides.length;});},{passive:true});
    element.addEventListener('mouseenter',()=>{hovered=true;clear();});element.addEventListener('mouseleave',()=>{hovered=false;schedule();});
    element.addEventListener('focusin',clear);element.addEventListener('focusout',()=>queueMicrotask(schedule));
    if('IntersectionObserver' in window){const observer=new IntersectionObserver(entries=>{inView=entries[0].isIntersecting;schedule();},{threshold:.15});observer.observe(element);}else{inView=true;}
    widgets.push(schedule);schedule();
  });
  const refresh=()=>widgets.forEach(run=>run());
  document.addEventListener('visibilitychange',refresh);reduced.addEventListener('change',refresh);
  if(widgets.length)new MutationObserver(refresh).observe(root,{attributes:true,attributeFilter:['data-motion','style']});
})();
