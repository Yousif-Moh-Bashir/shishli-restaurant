import assert from 'node:assert/strict'
import {writeFile} from 'node:fs/promises'
const origin='http://127.0.0.1:8090'
const request=async path=>{
  const start=performance.now()
  const response=await fetch(origin+path,{headers:{Accept:'application/json'},signal:AbortSignal.timeout(20000)})
  const body=await response.text()
  assert.equal(response.status,200,path)
  return {ms:performance.now()-start,body}
}
const branches=JSON.parse((await request('/api/v1/branches')).body).data
assert.ok(branches.length,'Synthetic E2E branches required')
const branch=branches[0].id
const summary=samples=>{
  samples.sort((a,b)=>a-b)
  return {samples:samples.length,medianMs:(samples[14]+samples[15])/2,p95Ms:samples[Math.ceil(samples.length*0.95)-1],maxMs:samples.at(-1)}
}
const endpoints=[]
for(const path of ['/api/v1/branches','/api/v1/home?branch='+branch,'/api/v1/menu?branch='+branch]){
  await request(path)
  const samples=[]
  for(let index=0;index<30;index++)samples.push((await request(path)).ms)
  endpoints.push({path:path.replace(branch,':branch'),...summary(samples)})
}
const concurrency=8,total=64,latencies=[]
const start=performance.now()
for(let batch=0;batch<total/concurrency;batch++){
  const results=await Promise.all(Array.from({length:concurrency},()=>request('/up')))
  latencies.push(...results.map(result=>result.ms))
}
const durationMs=performance.now()-start
const report={origin,scope:'Synthetic E2E database on Oracle MySQL; local Windows/Docker Desktop; no network throttling or real restaurant data',endpoints,load:{path:'/up',requests:total,concurrency,durationMs,requestsPerSecond:total/(durationMs/1000),p95Ms:latencies.sort((a,b)=>a-b)[Math.ceil(total*0.95)-1]}}
await writeFile('docs/docker/evidence/seeded-performance.json',JSON.stringify(report,null,2))
console.log(JSON.stringify(report,null,2))
