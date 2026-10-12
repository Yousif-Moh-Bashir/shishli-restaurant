import assert from 'node:assert/strict'
import { resolve } from 'node:path'
import { browserClient } from './browser-client.mjs'

const browser = await browserClient(9369, resolve('.performance-check/cart-feedback'))
const branch = '11111111-1111-4111-8111-111111111111'
const product = { id: '22222222-2222-4222-8222-222222222222', name: 'دجاج الاختبار', price: '25.00', available: true, option_groups: [], images: [], category: { name: 'الدجاج' } }
const origin = 'http://127.0.0.1:5176'
const fixtureErrors = []
try {
  await browser.send('Page.addScriptToEvaluateOnNewDocument', { source: `localStorage.clear();localStorage.setItem('shishli.selectedBranch',${JSON.stringify(branch)})` })
  await browser.send('Page.addScriptToEvaluateOnNewDocument', { source: `window.cartTicks=[]; window.AudioContext=class {state='running';currentTime=0;destination={};createOscillator(){return {frequency:{setValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){},disconnect(){},start(at){cartTicks.push({start:at})},stop(at){cartTicks.at(-1).stop=at}}}createGain(){return {gain:{setValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){},disconnect(){}}}close(){return Promise.resolve()}}` })
  browser.onEvent('Fetch.requestPaused', async ({ requestId, request }) => {
    try {
      const path = new URL(request.url).pathname
      let data = {}, responseCode = 200
      if (path.endsWith('/branches')) data = [{ id: branch, name: 'فرع الاختبار', is_active: true, location: {} }]
      else if (path.endsWith('/menu')) data = { categories: [{ id: 'chicken', products: [product] }], availability: {} }
      else if (path.includes('/menu/products/')) data = product
      else if (path.endsWith('/categories') || path.endsWith('/gallery')) data = []
      else if (path.endsWith('/settings/public')) data = { restaurant: { name: 'شيشلي' }, ordering: {} }
      else if (path.endsWith('/status')) data = { is_open: true, accepts_orders: true }
      else if (path.endsWith('/business-hours')) data = { days: [] }
      else responseCode = 404
      await browser.send('Fetch.fulfillRequest', { requestId, responseCode, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }, { name: 'Access-Control-Allow-Origin', value: origin }, { name: 'Access-Control-Allow-Headers', value: 'Authorization, Content-Type' }, { name: 'Access-Control-Allow-Methods', value: 'GET, POST, OPTIONS' }], body: Buffer.from(JSON.stringify({ success: responseCode === 200, data })).toString('base64') })
    } catch (error) { fixtureErrors.push(error.message) }
  })
  await browser.send('Fetch.enable', { patterns: [{ urlPattern: '*://*/api/*', requestStage: 'Request' }] })
  await browser.go(origin, '/menu')
  await browser.wait('document.querySelector(".product-card .add-button:not(:disabled)")')
  await browser.evaluate(`window.testCart=document.querySelector('#app').__vue_app__.config.globalProperties.$pinia._s.get('cart'); window.addCalls=0; testCart.addItem=()=>{addCalls++;return new Promise(resolve=>{window.finishAdd=resolve})}`)
  await browser.click('.product-card .add-button', 'إضافة للسلة')
  await browser.wait('document.querySelector(".product-card .add-button").getAttribute("aria-busy")==="true"')
  assert.equal(await browser.evaluate('document.querySelector(".product-card .add-button").disabled'), true)
  assert.equal(await browser.evaluate('document.querySelector("#toast").classList.contains("visible")'), false, 'No success before the server result')
  assert.equal(await browser.evaluate('cartTicks.length'), 0, 'No sound before success')
  await browser.evaluate('document.querySelector(".product-card .add-button").click()')
  assert.equal(await browser.evaluate('addCalls'), 1, 'Pending button prevents repeated submissions')
  await browser.evaluate('finishAdd({ok:true})')
  await browser.wait('document.querySelector("#toast.success.visible.quick")')
  await browser.wait('getComputedStyle(document.querySelector("#toast")).opacity === "1"')
  assert.equal(await browser.evaluate('document.querySelector("#toast").textContent.trim()'), 'تمت الإضافة للسلة')
  assert.equal(await browser.evaluate('cartTicks.length'), 1)
  assert.equal(await browser.evaluate('cartTicks[0].stop-cartTicks[0].start'), .06)
  assert.equal(await browser.evaluate('document.querySelector("#toast").querySelectorAll("button").length'), 0, 'Quick message has no extra controls')
  for (const width of [390, 768, 1440]) {
    await browser.send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width === 390 })
    const visible = await browser.evaluate(`(()=>{const el=document.querySelector('#toast'),rect=el.getBoundingClientRect(),style=getComputedStyle(el);return rect.top>=0&&rect.bottom<innerHeight&&rect.left>=0&&rect.right<=innerWidth&&style.visibility==='visible'&&style.opacity==='1'})()`)
    assert.equal(visible, true, 'Quick message is visible at ' + width)
  }
  await browser.wait('!document.querySelector("#toast").classList.contains("visible")')
  assert.equal(await browser.evaluate('document.querySelector("#cart-dialog").open'), false)
  await browser.evaluate('testCart.addItem=async()=>({ok:false,message:"تعذر إضافة المنتج"})')
  await browser.evaluate('document.querySelector(".product-card .add-button").click()')
  await browser.wait('document.querySelector("#toast.error.visible")')
  assert.equal(await browser.evaluate('cartTicks.length'), 1, 'Failed addition does not play success sound')
  assert.equal(await browser.evaluate('!!document.querySelector(".toast-cart-action")'), false)
  assert.ok(await browser.evaluate('document.querySelector("#toast").textContent.includes("تعذر إضافة المنتج")'))
  await browser.evaluate(`testCart.addItem=()=>new Promise(resolve=>{window.finishAdd=resolve});document.querySelector('#app').__vue_app__.config.globalProperties.$router.push('/product/${product.id}')`)
  await browser.wait('document.querySelector(".detail-order button:not(:disabled)")')
  await browser.click('.detail-order button', 'إضافة للسلة')
  await browser.wait('document.querySelector(".detail-order button").getAttribute("aria-busy")==="true"')
  await browser.evaluate('finishAdd({ok:true})')
  await browser.wait('document.querySelector("#toast.success.visible.quick")')
  assert.equal(await browser.evaluate('cartTicks.length'), 2)
  assert.deepEqual(browser.diagnostics, [])
  assert.deepEqual(fixtureErrors, [])
  console.log(JSON.stringify({ passed: true, checks: ['pending without premature sound or success', 'duplicate click prevention', '60ms tick only on success', 'short message without controls', 'automatic dismissal', 'responsive toast', 'failure feedback', 'product detail feedback'], transport: 'API and audio fixtures' }))
} finally { browser.close() }
