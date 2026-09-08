/* Analytic mass-spring response shared by real interactions and the inspector. */
(function(root,factory){const api=factory();if(typeof module==='object'&&module.exports)module.exports=api;if(root)root.CYSpring=api;})(typeof window==='undefined'?null:window,()=>{
  'use strict';
  function parameters(input={}) {
    const finite=(value,fallback,min,max)=>Number.isFinite(Number(value))?Math.max(min,Math.min(max,Number(value))):fallback;
    return {stiffness:finite(input.stiffness,180,40,480),damping:finite(input.damping,24,8,60),mass:finite(input.mass,1,.4,2.5)};
  }
  // Exact solution for a constant target over dt; retains velocity on retarget.
  function advance(state,target,dt,input={}) {
    const p=parameters(input),x=Number(state.x),v=Number(state.v),goal=Number(target),time=Number(dt);
    if(![x,v,goal,time].every(Number.isFinite)||time<0)throw new TypeError('Finite state, target and non-negative dt are required');
    if(time===0)return {x,v};
    const y=x-goal,a=p.damping/(2*p.mass),w2=p.stiffness/p.mass,d=a*a-w2;
    let ny,nv;
    if(Math.abs(d)<1e-8){const b=v+a*y,e=Math.exp(-a*time);ny=e*(y+b*time);nv=e*(v-a*b*time);}
    else if(d<0){const w=Math.sqrt(-d),b=(v+a*y)/w,c=Math.cos(w*time),s=Math.sin(w*time),e=Math.exp(-a*time);ny=e*(y*c+b*s);nv=e*(-a*(y*c+b*s)-y*w*s+b*w*c);}
    else{const r1=-a+Math.sqrt(d),r2=-a-Math.sqrt(d),c1=(v-r2*y)/(r1-r2),c2=y-c1,e1=Math.exp(r1*time),e2=Math.exp(r2*time);ny=c1*e1+c2*e2;nv=r1*c1*e1+r2*c2*e2;}
    return {x:goal+ny,v:nv};
  }
  function sample(input={}){
    const p=parameters(input),points=[{time:0,value:0}];let state={x:0,v:0},settledFrames=0;
    for(let i=1;i<=600;i++){state=advance(state,1,1/60,p);points.push({time:i/60,value:state.x});settledFrames=Math.abs(1-state.x)<.001&&Math.abs(state.v)<.001?settledFrames+1:0;if(settledFrames>=10)break;}
    return {points,duration:points[points.length-1].time*1000,ratio:p.damping/(2*Math.sqrt(p.stiffness*p.mass)),settled:settledFrames>=10};
  }
  return Object.freeze({parameters,advance,sample});
});
