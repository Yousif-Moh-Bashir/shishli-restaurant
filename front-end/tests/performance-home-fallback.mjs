import assert from 'node:assert/strict'
import {readFile,writeFile} from 'node:fs/promises'
import {resolve} from 'node:path'
import {browserClient} from './browser-client.mjs'
const fixture=JSON.parse(await readFile('.performance-check/audit-mysql.json','utf8'))
assert.match(fixture.database,/^shishli_perf_[a-f0-9]{12}$/)
const api='http://127.0.0.1:8019/api/v1',origin='http://127.0.0.1:4179'
async function call(path,method='GET',body){const response=await fetch(api+path,{method,headers:{Accept:'application/json',Authorization:'Bearer '+fixture.tokens.admin,...(body?{'Content-Type':'application/json'}:{})},body:body?JSON.stringify(body):undefined});assert.ok(response.ok,path+':'+response.status);return(await response.json()).data}
const home=(await call('/admin/pages')).find(page=>page.key==='home')
assert.ok(home)
const browser=await browserClient(9456,resolve('.performance-check/home-fallback')),requests=[]
browser.onEvent('Network.responseReceived',({response})=>{if(response.url.startsWith(api))requests.push({path:new URL(response.url).pathname,status:response.status})})
const count=path=>requests.filter(row=>row.path==='/api/v1/'+path).length
try{
 await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.clear();sessionStorage.clear();localStorage.setItem('shishli.selectedBranch',${JSON.stringify(fixture.branch)})`})
 await browser.send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true})
 await call('/admin/pages/'+home.id,'PATCH',{status:'draft'})
 await browser.go(origin,'/');await browser.wait('document.querySelector("#products-grid .product-card")');await browser.sleep(900)
 assert.equal(count('menu'),1);assert.equal(count('gallery'),1);assert.equal(count('categories'),0)
 assert.equal(requests.find(row=>row.path==='/api/v1/home')?.status,404)
 const fallback=[...requests];requests.length=0
 await call('/admin/pages/'+home.id,'PATCH',{status:'published'})
 await browser.go(origin,'/');await browser.wait('document.querySelector("#products-grid .product-card")');await browser.sleep(900)
 assert.equal(count('menu'),0);assert.equal(count('gallery'),0);assert.equal(count('categories'),0)
 const productName=await browser.evaluate('document.querySelector("#products-grid .product-card h3").textContent')
 await browser.evaluate('document.querySelector("[data-search-open]").click()');await browser.input('#product-search',productName)
 await browser.wait('document.querySelector("#products-grid")?.innerText.includes('+JSON.stringify(productName)+')');await browser.sleep(600)
 assert.equal(count('menu'),1)
 await browser.input('#product-search','');await browser.sleep(700);assert.equal(count('menu'),1)
 assert.deepEqual(browser.diagnostics,[])
 await writeFile('../docs/performance/home-fallback.json',JSON.stringify({result:'passed',fallback,publishedAndSearch:requests,checks:['Draft CMS: products remain available; one menu and one gallery request, no categories','Published CMS: no redundant menu/gallery/categories','One debounced search; clearing reuses CMS products'],diagnostics:browser.diagnostics},null,2))
 console.log('Home CMS failure fallback and published/search request reuse passed')
}finally{await call('/admin/pages/'+home.id,'PATCH',{status:'published'});browser.close()}
