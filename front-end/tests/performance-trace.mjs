import { readFile, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { browserClient } from './browser-client.mjs'
const stage = process.env.PERF_STAGE || 'before'
const fixture = JSON.parse(await readFile('.performance-check/audit-mysql.json', 'utf8'))
const browser = await browserClient(9452, resolve('.performance-check/audit-trace'))
try {
  await browser.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true })
  await browser.send('Performance.enable')
  await browser.send('Emulation.setCPUThrottlingRate', { rate: 4 })
  await browser.send('Network.emulateNetworkConditions', { offline: false, latency: 100, downloadThroughput: 1600000 / 8, uploadThroughput: 750000 / 8 })
  await browser.send('Network.clearBrowserCache')
  await browser.send('Page.addScriptToEvaluateOnNewDocument', { source: `localStorage.clear();localStorage.setItem('shishli.selectedBranch',${JSON.stringify(fixture.branch)});window.__shifts=[];new PerformanceObserver(list=>{for(const e of list.getEntries())if(!e.hadRecentInput)window.__shifts.push({time:e.startTime,value:e.value,sources:e.sources.map(s=>({node:s.node?.className||s.node?.tagName,previous:s.previousRect.toJSON(),current:s.currentRect.toJSON()}))})}).observe({type:'layout-shift',buffered:true})` })
  let stream
  browser.onEvent('Tracing.tracingComplete', result => { stream = result.stream })
  await browser.send('Tracing.start', { categories: 'devtools.timeline,blink.user_timing', transferMode: 'ReturnAsStream' })
  await browser.go('http://127.0.0.1:4179', '/product/' + fixture.product)
  await browser.sleep(5000)
  const metrics = await browser.send('Performance.getMetrics')
  const shifts = await browser.evaluate('window.__shifts')
  await browser.send('Tracing.end')
  for (let i = 0; !stream && i < 40; i++) await browser.sleep(100)
  let data = '', eof = false
  while (!eof) { const result = await browser.send('IO.read', { handle: stream }); data += result.data; eof = result.eof }
  await browser.send('IO.close', { handle: stream })
  for (const token of Object.values(fixture.tokens)) data = data.replaceAll(token, '[REDACTED]')
  await writeFile('../docs/performance/' + stage + '-chrome-trace.json', data)
  await writeFile('../docs/performance/' + stage + '-chrome-metrics.json', JSON.stringify({ metrics, shifts }, null, 2))
  console.log(JSON.stringify({ stage, shifts: shifts.map(s => ({ value: s.value, nodes: s.sources.map(v => v.node) })) }))
} finally { browser.close() }
