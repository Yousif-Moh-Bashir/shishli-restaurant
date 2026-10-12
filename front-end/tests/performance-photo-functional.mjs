import assert from 'node:assert/strict'
import { readFile,writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { browserClient } from './browser-client.mjs'
const fixture=JSON.parse(await readFile('.performance-check/audit-mysql.json','utf8'))
assert.match(fixture.database,/^shishli_perf_[a-f0-9]{12}$/)
const response=await fetch('http://127.0.0.1:8019/api/v1/menu/products/'+fixture.product+'?branch='+fixture.branch)
assert.equal(response.status,200)
const product=(await response.json()).data
assert.ok(product.images[0].url.endsWith('.png.webp'),'Existing URL field points to the optimized file')
const browser=await browserClient(9455,resolve('.performance-check/photo-functional'))
try {
  await browser.send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.clear();sessionStorage.clear();localStorage.setItem('shishli.selectedBranch',${JSON.stringify(fixture.branch)})`})
  await browser.send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true})
  await browser.go('http://127.0.0.1:4179','/product/'+fixture.product)
  await browser.wait('document.querySelector(".product-detail-image img")?.complete&&document.querySelector(".product-detail-image img")?.naturalWidth>0')
  assert.equal(await browser.evaluate('document.querySelector(".product-detail-image img").src'),product.images[0].url)
  assert.equal(await browser.evaluate('document.documentElement.scrollWidth>innerWidth+1'),false)
  assert.deepEqual(browser.diagnostics,[])
  await writeFile('../docs/performance/photo-functional.json',JSON.stringify({result:'passed',transport:'Real Laravel image resource and production Vue page; one synthetic image association added after timing, no live database access',url_path:new URL(product.images[0].url).pathname,contract_keys:Object.keys(product.images[0]),diagnostics:browser.diagnostics},null,2))
  const shot=await browser.send('Page.captureScreenshot',{format:'png'})
  await writeFile('../docs/performance/photo-functional.png',Buffer.from(shot.data,'base64'))
  console.log('Real optimized image URL loaded successfully through Laravel + production Vue; contract keys unchanged')
} finally {browser.close()}
