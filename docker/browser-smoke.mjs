import assert from 'node:assert/strict'
import {resolve} from 'node:path'
import {writeFile,mkdir} from 'node:fs/promises'
import {browserClient} from '../front-end/tests/browser-client.mjs'
const origin=process.env.SHISHLI_URL||'http://127.0.0.1:8088'
const browser=await browserClient(9392,resolve('docs/docker/evidence/private/smoke'))
const report={origin,pages:[],metrics:[],diagnostics:[],scope:'Chrome localhost; empty production database; desktop/mobile; no customer mutations'}
try{
  await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:`new PerformanceObserver(list=>{window.shishliLcp=list.getEntries().at(-1)?.startTime}).observe({type:'largest-contentful-paint',buffered:true});window.shishliCls=0;new PerformanceObserver(list=>{for(const e of list.getEntries())if(!e.hadRecentInput)window.shishliCls+=e.value}).observe({type:'layout-shift',buffered:true});`})
  for(const width of [390,1366]){
    await browser.send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<700})
    for(const path of ['/','/menu','/branches','/cart','/checkout','/login','/register']){
      await browser.go(origin,path);await browser.sleep(500)
      const page=await browser.evaluate(`({path:location.pathname,width:innerWidth,overflow:document.documentElement.scrollWidth>innerWidth+1,main:!!document.querySelector('main'),textLength:document.body.innerText.length,metrics:{lcpMs:window.shishliLcp,cls:window.shishliCls,domContentLoadedMs:performance.getEntriesByType('navigation')[0]?.domContentLoadedEventEnd}})`)
      assert.equal(page.overflow,false,path+' overflow '+width);assert.ok(page.textLength>0);report.pages.push(page)
    }
  }
  report.diagnostics=browser.diagnostics
  assert.deepEqual(browser.diagnostics,[])
  report.result='passed'
}catch(error){report.result='failed';report.error=error.message;throw error}
finally{browser.close();await mkdir('docs/docker/evidence',{recursive:true});await writeFile('docs/docker/evidence/browser-smoke.json',JSON.stringify(report,null,2));console.log(JSON.stringify({result:report.result,pages:report.pages.length,diagnostics:report.diagnostics,error:report.error}))}
