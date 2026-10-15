import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
const origin = process.env.SHISHLI_URL || 'http://127.0.0.1:8088'
const results = []
for (const [path, status] of [['/',200],['/menu',200],['/up',200],['/api/v1/health',200],['/api/user',401],['/api/v1/admin/me',401],['/.env',403],['/index.php',404]]) {
  const response = await fetch(origin + path, {headers:{Accept:'application/json'}})
  await response.text()
  results.push({path,status:response.status})
  assert.equal(response.status,status,path)
}
const homeResponse = await fetch(origin)
assert.equal(homeResponse.headers.get('x-content-type-options'),'nosniff')
assert.equal(homeResponse.headers.get('x-frame-options'),'SAMEORIGIN')
assert.equal(homeResponse.headers.get('cache-control'),'no-cache')
const home = await homeResponse.text()
const assets = [...home.matchAll(/(?:src|href)="(\/assets\/[^" ]+)"/g)].map(m=>m[1])
assert.ok(assets.length)
for (const path of assets) {
  const response = await fetch(origin+path,{headers:{'Accept-Encoding':'gzip'}})
  await response.arrayBuffer()
  assert.equal(response.status,200)
  assert.match(response.headers.get('cache-control'),/immutable/)
  results.push({path,status:200,gzip:response.headers.get('content-encoding')})
}
const performanceResults=[]
for (const path of ['/','/up','/api/v1/health','/api/v1/branches']) {
  const samples=[]
  for(let i=0;i<31;i++) {
    const start=performance.now(); const response=await fetch(origin+path,{headers:{Accept:'application/json'}}); await response.arrayBuffer()
    assert.equal(response.status,200,path)
    if(i) samples.push(performance.now()-start)
  }
  samples.sort((a,b)=>a-b)
  performanceResults.push({path,samples:30,medianMs:(samples[14]+samples[15])/2,p95Ms:samples[28],meanMs:samples.reduce((a,b)=>a+b)/30})
}
await mkdir('docs/docker/evidence',{recursive:true})
await writeFile('docs/docker/evidence/http-performance.json',JSON.stringify({origin,tests:results,performance:performanceResults,scope:'Sequential localhost, empty production database, one warmup per endpoint'},null,2))
console.log(JSON.stringify({tests:results,performance:performanceResults},null,2))
