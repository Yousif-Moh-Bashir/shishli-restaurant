import assert from 'node:assert/strict'
import {readFile,writeFile} from 'node:fs/promises'
const api=process.env.DESIGN_API_URL
assert.equal(new URL(api).port,'8017','Requires isolated QA API')
const journey=JSON.parse(await readFile(new URL('../../docs/qa/journey-browser.json',import.meta.url),'utf8'))
assert.equal(journey.result,'passed')
const id=journey.pages.find(path=>path.startsWith('/account/orders/')).split('/').at(-1)
let token
async function call(path,body){const response=await fetch(api+path,{method:body?'POST':'GET',headers:{Accept:'application/json',...(token?{Authorization:'Bearer '+token}:{}),...(body?{'Content-Type':'application/json'}:{})},body:body?JSON.stringify(body):undefined});assert.ok(response.ok,path+' '+response.status);return (await response.json()).data}
try{
 token=(await call('/login',{email:'super_admin@demo.shishli.local',password:'DemoTest123!'})).token
 const order=await call('/admin/orders/'+id),date=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Riyadh',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date(order.placed_at))
 const report=await call('/admin/reports/orders?date_from='+date+'&date_to='+date+'&branch_uuid='+order.branch.id+'&order_status=completed&payment_method=cash&per_page=100')
 const row=report.data.find(item=>item.order_uuid===id);assert.ok(row,'Delivered journey order is in sales/orders report');assert.equal(row.status,'completed');assert.equal(row.total,order.pricing.total)
 const evidence={result:'passed',checks:['Real delivered cash journey order appears in completed cash orders report','Report total equals immutable admin order total'],order_uuid:id,total:row.total,date}
 await writeFile(new URL('../../docs/qa/report-reconciliation.json',import.meta.url),JSON.stringify(evidence,null,2));console.log(JSON.stringify(evidence))
}finally{if(token)await call('/logout',{}).catch(()=>{})}
