import assert from 'node:assert/strict'
import { readFile, writeFile, mkdir } from 'node:fs/promises'
import { resolve } from 'node:path'
import { browserClient } from './browser-client.mjs'

const stage = process.env.PERF_STAGE || 'before', mode = process.env.PERF_MODE || 'production'
const origin = mode === 'development' ? 'http://127.0.0.1:5179' : 'http://127.0.0.1:4179'
const api = 'http://127.0.0.1:8019/api/v1'
const fixture = JSON.parse(await readFile('.performance-check/audit-mysql.json', 'utf8'))
assert.match(fixture.database, /^shishli_perf_[a-f0-9]{12}$/)
const directory = resolve(process.env.PERF_OUTPUT_DIRECTORY || '../docs/performance')
const repetitions = Number(process.env.PERF_REPETITIONS || 3)
assert.ok(Number.isInteger(repetitions) && repetitions >= 1 && repetitions <= 10)
await mkdir(directory, { recursive: true })
const browser = await browserClient(9451, resolve('.performance-check/full-audit'))
const routes = [['Home', '/', null], ['Menu', '/menu', null], ['Product', '/product/' + fixture.product, null], ['Cart', '/cart', 'customer'], ['Checkout', '/checkout', 'customer'], ['Login', '/login', null], ['Addresses', '/account/addresses', 'customer'], ['Tracking', '/track-order/' + fixture.order, 'customer'], ['Admin Dashboard', '/admin', 'admin'], ['Admin Orders', '/admin/orders', 'admin'], ['Driver Orders', '/driver/orders', 'driver']]
const report = { stage, mode, engine: 'mysql', transport: 'real isolated Laravel API', viewport: '390x844 DPR1', cpuSlowdown: 4, network: '1.6Mbps down, 750Kbps up, 100ms latency', repetitions, pages: [], diagnostics: [] }
let current = [], identity, failures = []
browser.onEvent('Network.requestWillBeSent', ({ requestId, request }) => { current.push({ id: requestId, url: request.url, method: request.method, start: performance.now() }) })
browser.onEvent('Network.responseReceived', ({ requestId, response, type }) => {
  const entry = current.find(item => item.id === requestId)
  if (entry) Object.assign(entry, { status: response.status, type, received: performance.now(), timing: response.headers['Server-Timing'] || response.headers['server-timing'] || null })
})
browser.onEvent('Network.loadingFinished', ({ requestId, encodedDataLength }) => { const entry = current.find(item => item.id === requestId); if (entry) Object.assign(entry, { bytes: encodedDataLength, end: performance.now() }) })
browser.onEvent('Network.loadingFailed', ({ requestId, errorText, canceled }) => { const entry = current.find(item => item.id === requestId); if (entry && !canceled) failures.push({ path: new URL(entry.url).pathname, error: errorText }) })
async function call(path, method = 'GET', body, token = fixture.tokens.customer) {
  const response = await fetch(api + path, { method, headers: { Accept: 'application/json', Authorization: 'Bearer ' + token, ...(body ? { 'Content-Type': 'application/json' } : {}) }, body: body ? JSON.stringify(body) : undefined })
  assert.ok(response.ok, path + ':' + response.status)
  return (await response.json()).data
}
try {
  // A populated customer cart is required to measure the actual Cart/Checkout pages.
  await call('/cart', 'POST', { branch_uuid: fixture.branch })
  await call('/cart/items', 'DELETE')
  await call('/cart/items', 'POST', { branch_uuid: fixture.branch, product_uuid: fixture.product, quantity: 3, options: [] })
  await browser.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true })
  await browser.send('Emulation.setCPUThrottlingRate', { rate: 4 })
  await browser.send('Network.emulateNetworkConditions', { offline: false, latency: 100, downloadThroughput: 1600000 / 8, uploadThroughput: 750000 / 8 })
  for (const [name, path, role] of routes.filter(route => !process.env.PERF_PAGES || process.env.PERF_PAGES.split(',').includes(route[0]))) {
    for (let sample = 0; sample < repetitions; sample++) {
      if (identity) await browser.send('Page.removeScriptToEvaluateOnNewDocument', { identifier: identity })
      const result = await browser.send('Page.addScriptToEvaluateOnNewDocument', { source: `localStorage.clear();sessionStorage.clear();localStorage.setItem('shishli.selectedBranch',${JSON.stringify(fixture.branch)});${role ? `localStorage.setItem('shishli_auth_token',${JSON.stringify(fixture.tokens[role])});` : ''}
        window.__audit={lcp:0,cls:0,events:[],longTasks:[]};let group=0,first=0,last=0;
        new PerformanceObserver(list=>{for(const e of list.getEntries()){window.__audit.lcp=e.startTime;window.__audit.lcpElement=e.element?.className||e.element?.tagName;}}).observe({type:'largest-contentful-paint',buffered:true});
        new PerformanceObserver(list=>{for(const e of list.getEntries()){if(e.hadRecentInput)continue;if(e.startTime-last>1000||e.startTime-first>5000){group=0;first=e.startTime}group+=e.value;last=e.startTime;window.__audit.cls=Math.max(window.__audit.cls,group)}}).observe({type:'layout-shift',buffered:true});
        new PerformanceObserver(list=>{for(const e of list.getEntries())if(e.interactionId)window.__audit.events.push({id:e.interactionId,duration:e.duration})}).observe({type:'event',buffered:true,durationThreshold:16});
        new PerformanceObserver(list=>{window.__audit.longTasks.push(...list.getEntries().map(e=>e.duration))}).observe({type:'longtask',buffered:true});` })
      identity = result.identifier
      await browser.send('Network.clearBrowserCache')
      current = []; failures = []
      await browser.go(origin, path)
      await browser.sleep(5000)
      for (let idle = 0; idle < 60; idle++) {
        if (!current.some(item => ['Script', 'Stylesheet', 'Image'].includes(item.type) && !item.end)) break
        await browser.sleep(500)
      }
      await browser.sleep(500)
      // A real pointer interaction (no synthetic DOM click) measures local event latency.
      // Pointer down/up on a focused form input avoids route-changing the measured page.
      const input = await browser.evaluate(`(()=>{const e=document.querySelector('main input:not([type=hidden])');if(!e)return null;const r=e.getBoundingClientRect();const x=r.x+r.width/2,y=r.y+r.height/2;return r.width>0&&r.height>0&&x>=0&&x<innerWidth&&y>=0&&y<innerHeight?{x,y}:null})()`)
      if (input) { await browser.send('Input.dispatchMouseEvent', { type: 'mousePressed', ...input, button: 'left', clickCount: 1 }); await browser.send('Input.dispatchMouseEvent', { type: 'mouseReleased', ...input, button: 'left', clickCount: 1 }); await browser.sleep(200) }
      const metrics = await browser.evaluate(`(()=>{const n=performance.getEntriesByType('navigation')[0];const p=performance.getEntriesByType('paint');return {...window.__audit,fcp:p.find(e=>e.name==='first-contentful-paint')?.startTime??null,ttfb:n.responseStart-n.requestStart,dom:n.domContentLoadedEventEnd,overflow:document.documentElement.scrollWidth>innerWidth+1}})()`)
      const resources = current.filter(item => item.end && item.method !== 'OPTIONS')
      const apis = resources.filter(item => item.url.startsWith(api) || new URL(item.url).pathname === '/api/user')
      const sum = type => resources.filter(item => item.type === type).reduce((sum, item) => sum + item.bytes, 0)
      const row = { name, path: path.replace(fixture.product, ':product').replace(fixture.order, ':order'), sample, ...metrics, interaction_ms: metrics.events.length ? Math.max(...metrics.events.map(e => e.duration)) : null, js_bytes: sum('Script'), css_bytes: sum('Stylesheet'), image_bytes: sum('Image'), api_count: apis.length,
        api: apis.map(item => ({ path: new URL(item.url).pathname.replace(fixture.branch, ':branch').replace(fixture.product, ':product').replace(fixture.order, ':order'), status: item.status, ms: +(item.end - item.start).toFixed(2), serverTiming: item.timing })),
        assets: resources.filter(item => ['Script', 'Stylesheet', 'Image', 'Font'].includes(item.type)).map(item => ({ path: new URL(item.url).pathname, type: item.type, bytes: item.bytes })), failures }
      report.pages.push(row)
      if (sample === 0) {
        const shot = await browser.send('Page.captureScreenshot', { format: 'png' })
        await writeFile(resolve(directory, `${stage}-${mode}-${name.replaceAll(' ', '-')}.png`), Buffer.from(shot.data, 'base64'))
      }
      console.log(`${stage} ${mode} ${name} #${sample}: FCP=${Math.round(metrics.fcp)} LCP=${Math.round(metrics.lcp)} CLS=${metrics.cls.toFixed(3)} JS=${Math.round(row.js_bytes / 1024)}KB images=${Math.round(row.image_bytes / 1024)}KB API=${apis.length}`)
      await writeFile(resolve(directory, `${stage}-${mode}.json`), JSON.stringify(report, null, 2))
    }
  }
  report.diagnostics = browser.diagnostics
  await writeFile(resolve(directory, `${stage}-${mode}.json`), JSON.stringify(report, null, 2))
} finally { browser.close() }
