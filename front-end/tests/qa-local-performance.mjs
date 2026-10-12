import assert from 'node:assert/strict'
import {writeFile} from 'node:fs/promises'
const api=process.env.DESIGN_API_URL
assert.equal(new URL(api).port,'8017','Only the isolated QA API is permitted')
const report={environment:'Isolated Laravel + seeded SQLite, development server; not a production benchmark',samples:[]}
const branches=await (await fetch(api+'/branches')).json()
for(const path of ['/settings/public','/menu?branch='+branches.data[0].id,'/products','/branches']){
 const measurements=[];let bytes=0
 for(let index=0;index<10;index++){const start=performance.now(),response=await fetch(api+path,{headers:{Accept:'application/json'},signal:AbortSignal.timeout(20000)});assert.equal(response.status,200);const body=await response.text();bytes=Buffer.byteLength(body);measurements.push(performance.now()-start)}
 measurements.sort((a,b)=>a-b)
 report.samples.push({path,requests:10,p50_ms:Math.round(measurements[4]),p95_ms:Math.round(measurements[9]),payload_bytes:bytes})
}
await writeFile(new URL('../../docs/qa/local-performance.json',import.meta.url),JSON.stringify(report,null,2))
console.log(JSON.stringify(report))
