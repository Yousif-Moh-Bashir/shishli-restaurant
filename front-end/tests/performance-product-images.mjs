import { readFile, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import assert from 'node:assert/strict'
import { browserClient } from './browser-client.mjs'
const images = JSON.parse(await readFile('../docs/performance/product-image-optimization.json','utf8'))
const browser = await browserClient(9454,resolve('.performance-check/product-images'))
const results=[]
try {
  await browser.send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true})
  await browser.send('Emulation.setCPUThrottlingRate',{rate:4})
  await browser.send('Network.emulateNetworkConditions',{offline:false,latency:100,downloadThroughput:1600000/8,uploadThroughput:750000/8})
  let requests=[]
  browser.onEvent('Network.requestWillBeSent',({requestId,request})=>requests.push({id:requestId,url:request.url,start:performance.now()}))
  browser.onEvent('Network.loadingFinished',({requestId,encodedDataLength})=>{const entry=requests.find(row=>row.id===requestId);if(entry)Object.assign(entry,{bytes:encodedDataLength,end:performance.now()})})
  for (const image of images) {
    assert.match(image.path,/^products\/.+\.png\.webp$/)
    for (const stage of ['original','optimized']) {
      const path=stage==='original'?image.path.slice(0,-5):image.path
      const url='http://127.0.0.1:8030/'+path
      await browser.send('Network.clearBrowserCache'); requests=[]
      await browser.send('Page.navigate',{url})
      await browser.wait('document.querySelector("img")?.complete && document.querySelector("img")?.naturalWidth>0')
      await browser.sleep(200)
      const entry=requests.find(row=>row.url===url&&row.end)
      assert.ok(entry,'Completed image transfer '+path)
      results.push({stage,path,transfer_bytes:entry.bytes,request_ms:entry.end-entry.start})
    }
  }
  assert.deepEqual(browser.diagnostics,[])
  await writeFile('../docs/performance/product-image-transfers.json',JSON.stringify({protocol:'One isolated static-file browser image-viewer request per original/variant; same 390x844, CPU4x, 1.6Mbps/750Kbps/100ms; no Laravel or customer database. Transfer comparison, not Product page LCP.',results},null,2))
  console.log(JSON.stringify(results))
} finally {browser.close()}
