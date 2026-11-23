(function(root){
'use strict';
const timestamp=r=>new Date(r.measured_at.replace(' ','T')+'+03:30').getTime();
const mean=arr=>arr.length?arr.reduce((a,b)=>a+Number(b),0)/arr.length:null;
const patientRows=(rows,patientId)=>rows.filter(r=>Number(r.patient_id)===Number(patientId));
function historyRows(rows,patientId,{includeVoided=false,from='',to='',kind='all'}={}){return patientRows(rows,patientId).filter(r=>(includeVoided||!r.voided_at)&&(!from||r.measured_at.slice(0,10)>=from)&&(!to||r.measured_at.slice(0,10)<=to)&&(kind==='all'||r.kind===kind)).sort((a,b)=>timestamp(b)-timestamp(a)||Number(b.id)-Number(a.id));}
function groups(rows){const m=new Map();for(const r of rows.filter(x=>x.kind==='bp'&&!x.voided_at)){const key=r.group_uuid||r.uuid;if(!m.has(key))m.set(key,[]);m.get(key).push(r);}return [...m.values()].map(g=>{g.sort((a,b)=>timestamp(a)-timestamp(b));return {rows:g,time:timestamp(g[0]),slot:g[0].slot,systolic:mean(g.map(r=>r.systolic)),diastolic:mean(g.map(r=>r.diastolic)),pulse:mean(g.map(r=>r.pulse)),gap:g.length>1?(timestamp(g[1])-timestamp(g[0]))/60000:null};}).sort((a,b)=>a.time-b.time);}
function summary(rows){const gs=groups(rows);return {groups:gs,count:gs.length,single:gs.filter(g=>g.rows.length===1).length,systolic:mean(gs.map(g=>g.systolic)),diastolic:mean(gs.map(g=>g.diastolic)),pulse:mean(gs.map(g=>g.pulse)),morning:gs.filter(g=>g.slot==='morning').length,evening:gs.filter(g=>g.slot==='evening').length};}
const api={timestamp,mean,patientRows,historyRows,groups,summary};root.HealthStats=api;if(typeof module!=='undefined')module.exports=api;
})(globalThis);
