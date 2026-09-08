/* No DOM and no third-party packages. Run: node tests/spring.test.js */
'use strict';
const assert=require('node:assert/strict'),S=require('../site/assets/spring.js'),tests=[];
function test(name,fn){try{fn();tests.push({test:name,ok:true});}catch(e){tests.push({test:name,ok:false,error:e.message});}}
for(const [name,p] of Object.entries({under:{stiffness:180,damping:16,mass:1},critical:{stiffness:100,damping:20,mass:1},over:{stiffness:100,damping:40,mass:1}})){
 test(name+' exact solver is time-step independent',()=>{const once=S.advance({x:.2,v:1.5},1,.5,p);let many={x:.2,v:1.5};for(let i=0;i<60;i++)many=S.advance(many,1,1/120,p);assert.ok(Math.abs(once.x-many.x)<1e-10);assert.ok(Math.abs(once.v-many.v)<1e-10);});
 test(name+' zero time preserves momentum on retarget',()=>assert.deepEqual(S.advance({x:.2,v:1.5},-1,0,p),{x:.2,v:1.5}));
 test(name+' converges to the target',()=>{const end=S.advance({x:10,v:-25},2,30,p);assert.ok(Math.abs(end.x-2)<1e-6);assert.ok(Math.abs(end.v)<1e-6);});
 test(name+' inspector samples settle using same solver',()=>{const sample=S.sample(p);assert.ok(sample.settled);const first=sample.points[1];assert.equal(first.value,S.advance({x:0,v:0},1,1/60,p).x);});
}
for(const stiffness of [40,180,480])for(const damping of [8,24,60])for(const mass of [.4,1,2.5])test('finite bounded parameter response '+[stiffness,damping,mass].join('/'),()=>{let state={x:0,v:0};for(let i=0;i<120;i++){state=S.advance(state,i<60?1:-1,1/60,{stiffness,damping,mass});assert.ok(Number.isFinite(state.x)&&Number.isFinite(state.v));assert.ok(Math.abs(state.x)<4);}});
test('invalid state rejected',()=>assert.throws(()=>S.advance({x:NaN,v:0},1,.1)));
test('negative time rejected',()=>assert.throws(()=>S.advance({x:0,v:0},1,-1)));
test('nonfinite parameters use safe defaults',()=>assert.deepEqual(S.parameters({stiffness:Infinity,damping:NaN,mass:undefined}),{stiffness:180,damping:24,mass:1}));
console.log(JSON.stringify({total:tests.length,passed:tests.filter(t=>t.ok).length,failed:tests.filter(t=>!t.ok).length,tests},null,2));process.exitCode=tests.some(t=>!t.ok)?1:0;
