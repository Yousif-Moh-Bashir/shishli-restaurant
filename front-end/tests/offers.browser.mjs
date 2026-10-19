// UI fixtures exercise API contracts in Chrome; no fixture is added to application data.
import assert from 'node:assert/strict'
import { resolve } from 'node:path'
import { writeFile } from 'node:fs/promises'
import { browserClient } from './browser-client.mjs'

const output = resolve('.performance-check/offers')
const browser = await browserClient(9392, output)
const origin = process.env.FRONTEND_ORIGIN || 'http://127.0.0.1:5176'
const id = n => String(n).padStart(8, '0') + '-1111-4111-8111-111111111111'
const branch = { id: id(1), name: 'فرع الاختبار', is_active: true, supports_pickup: true, supports_delivery: true, location: { city: 'الرياض' } }
const automatic = [
  { id: id(2), name: 'ترحيب شيشلي', description: 'ترحيب شيشلي. نكهة تركية تجمعكم حول المائدة. نكهة تركية تجمعكم حول المائدة.', type: 'automatic', application_type: 'cart', discount: { type: 'percentage', value: '20.00', maximum: '30.00' }, minimum_subtotal: '80.00', first_order_only: true, ends_at: '2030-01-01T21:00:00Z' },
  { id: id(3), name: 'وفّر على طلبك القادم', description: 'وجباتك المفضلة بقيمة أجمل.', type: 'automatic', application_type: 'category', discount: { type: 'fixed', value: '25.75', maximum: null }, minimum_subtotal: '0.00', first_order_only: false, ends_at: null },
  { id: id(4), name: 'سعر خاص لأطباق مختارة', description: 'مذاق شيشلي من الشواية إلى مائدتك. '.repeat(15), type: 'automatic', application_type: 'product', discount: { type: 'fixed_price', value: '49.00', maximum: null }, minimum_subtotal: '0.00', first_order_only: false, ends_at: null },
]
const checks = [], responsive = [], requests = []
let mode = 'hold', held
const success = data => ({ success: true, message: 'Success', data, errors: null })
const paginated = data => ({ ...success(data), meta: { current_page: 1, last_page: 1, per_page: 20, total: data.length }, links: { prev: null, next: null } })
async function fulfill(requestId, status, body) {
  await browser.send('Fetch.fulfillRequest', { requestId, responseCode: status, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }, { name: 'Access-Control-Allow-Origin', value: '*' }, { name: 'Access-Control-Allow-Headers', value: 'Authorization,Content-Type,X-Cart-Token,X-Cart-UUID' }, { name: 'Access-Control-Allow-Methods', value: 'GET,POST,OPTIONS' }], body: Buffer.from(JSON.stringify(body)).toString('base64') })
}
browser.onEvent('Fetch.requestPaused', async ({ requestId, request }) => {
  try {
    const url = new URL(request.url), path = url.pathname
    requests.push({ path, method: request.method })
    if (request.method === 'OPTIONS') return fulfill(requestId, 200, {})
    if (path.endsWith('/promotions')) {
      if (url.searchParams.has('registration_offer')) return fulfill(requestId, 200, paginated([]))
      if (mode === 'hold') { held = requestId; return }
      return fulfill(requestId, mode === 'error' ? 503 : 200, mode === 'error' ? { success: false, message: 'Unavailable' } : paginated(mode === 'empty' ? [] : automatic))
    }
    if (path.endsWith('/branches')) return fulfill(requestId, 200, success([branch]))
    if (path.endsWith('/settings/public')) return fulfill(requestId, 200, success({ restaurant: { name: 'شيشلي', currency: 'SAR', timezone: 'Asia/Riyadh' }, orders: { enabled: true } }))
    if (path.endsWith('/business-hours')) return fulfill(requestId, 200, success({ days: [] }))
    if (path.endsWith('/status')) return fulfill(requestId, 200, success({ accepts_orders: true, pickup: { available: true }, delivery: { available: true } }))
    if (path.endsWith('/cart')) return fulfill(requestId, 200, success({ id: id(8), token: 'fixture-cart', branch, status: 'active', items: [], summary: { quantity_total: 0, items_count: 0, total: '0.00' } }))
    return fulfill(requestId, 200, success([]))
  } catch (error) { console.error(error); await browser.send('Fetch.failRequest', { requestId, errorReason: 'Failed' }).catch(() => {}) }
})
try {
  await browser.send('Fetch.enable', { patterns: [{ urlPattern: '*://*/api/v1/*' }, { urlPattern: '*://*/api/user' }] })
  await browser.send('Page.addScriptToEvaluateOnNewDocument', { source: "localStorage.clear();sessionStorage.clear()" })
  await browser.go(origin, '/offers')
  await browser.wait('document.querySelector("main .app-skeleton")')
  assert.equal(await browser.evaluate('document.querySelectorAll(".promotion-card").length'), 0)
  mode = 'success'
  for (let attempt = 0; !held && attempt < 100; attempt++) await browser.sleep(50)
  assert.ok(held)
  await fulfill(held, 200, paginated(automatic))
  await browser.wait('document.querySelectorAll(".promotion-card").length===3')
  assert.equal(await browser.evaluate('document.querySelectorAll("main h1").length'), 1)
  assert.equal(await browser.evaluate('document.querySelector(".promotion-value strong").textContent'), '20٪')
  assert.equal(await browser.evaluate('document.querySelector(".promotion-description").textContent'), 'نكهة تركية تجمعكم حول المائدة.')
  assert.equal(await browser.evaluate('document.querySelector("time").textContent.replace(/[\\u200e\\u200f]/g,"").includes("02/01/2030")'), true)
  assert.equal(await browser.evaluate('document.querySelectorAll(".promotion-card time").length'), 1)
  assert.equal(await browser.evaluate('document.querySelector("main").textContent.includes("application_type")||document.querySelector("main").textContent.includes("fixed_price")'), false)
  assert.equal(requests.some(row => row.path.endsWith('/promotions')), true)
  checks.push('Public offers retain API loading, real response contract, concise text, accurate values, conditions and business-timezone expiry')
  for (const width of [320, 375, 390, 768, 1440]) {
    await browser.send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 700 })
    await browser.sleep(120)
    assert.equal(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), true, 'Page overflow ' + width)
    assert.equal(await browser.evaluate('[...document.querySelectorAll(".promotion-card")].every(el=>{const r=el.getBoundingClientRect();return r.left>=-1&&r.right<=innerWidth+1})'), true)
    assert.equal(await browser.evaluate('[...document.querySelectorAll(".promotion-action")].every(el=>el.getBoundingClientRect().height>=44&&el.getAttribute("href")==="/menu")'), true)
    assert.equal(await browser.evaluate('[...document.querySelectorAll(".promotion-value strong")].every(el=>el.scrollWidth<=el.clientWidth+1)'), true)
    assert.equal(await browser.evaluate('getComputedStyle(document.querySelector(".promotion-card")).direction'), 'rtl')
    if (width < 700) assert.equal(await browser.evaluate('document.querySelector(".promotion-card").getBoundingClientRect().height<500'), true)
    const screenshot = await browser.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })
    await writeFile(resolve(output, 'offers-' + width + '.png'), Buffer.from(screenshot.data, 'base64'))
    responsive.push(width)
  }
  mode = 'empty'; await browser.go(origin, '/offers')
  await browser.wait('document.querySelector("main").textContent.includes("لا توجد عروض سارية")')
  assert.equal(await browser.evaluate('document.querySelectorAll(".promotion-card").length'), 0)
  mode = 'error'; await browser.go(origin, '/offers')
  await browser.wait('document.querySelector("main [role=alert]")')
  mode = 'success'; await browser.click('main [role=alert] button', 'إعادة المحاولة')
  await browser.wait('document.querySelectorAll(".promotion-card").length===3')
  checks.push('Empty offers and API failure do not show fake cards; retry restores actual API data')

  // The public API deliberately excludes coupons. Exercise its existing presentation contract in isolation.
  await browser.evaluate(String.raw`(async()=>{
    const source=await(await fetch('/src/components/site/SiteOffers.vue')).text();
    const {createApp,h,reactive}=await import(source.match(/from ["']([^"']*vue[.]js[^"']*)["']/)[1]);
    const {createRouter,createMemoryHistory}=await import(source.match(/from ["']([^"']*vue-router[^"']*)["']/)[1]);
    const {default:Offers}=await import('/src/components/site/SiteOffers.vue');
    const old=document.querySelector('#app').__vue_app__,pinia=old.config.globalProperties.$pinia;
    old.unmount();document.querySelector('#app').innerHTML='';
    const router=createRouter({history:createMemoryHistory(),routes:[{path:'/',component:{render:()=>null}},{path:'/menu',component:{render:()=>null}},{path:'/cart',component:{render:()=>null}}]});
    await router.push('/');
    window.offerFixture=reactive({items:[{id:'coupon-fixture',name:'كوبون خصم على طلبك',description:'أدخل الكوبون الذي تملكه عند مراجعة السلة.',type:'coupon',application_type:'cart',discount:{type:'fixed',value:'15.50',maximum:null},minimum_subtotal:'0.00',ends_at:null}]});
    const app=createApp({render:()=>h('main',{class:'site-layout'},[h(Offers,{offers:offerFixture.items})])});
    app.use(pinia);app.use(router);app.mount('#app');
  })()`)
  await browser.wait('document.querySelector(".promotion-action")?.getAttribute("href")==="/cart"')
  assert.equal(await browser.evaluate('document.querySelector(".promotion-value strong").textContent'), '15.5 ريال')
  assert.equal(await browser.evaluate('document.querySelectorAll(".promotion-conditions li").length'), 0)
  for (const width of [320, 375, 390, 768, 1440]) {
    await browser.send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 700 })
    assert.equal(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), true)
  }
  await browser.evaluate('offerFixture.items[0].name="عنوانعرضطويلبدونمسافات".repeat(20);offerFixture.items[0].description="وصف طويل للعرض ".repeat(50)')
  for (const width of [320, 375, 390]) {
    await browser.send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: true })
    assert.equal(await browser.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), true, 'Long text overflow ' + width)
    assert.equal(await browser.evaluate('document.querySelector(".promotion-title").getBoundingClientRect().height<=2*parseFloat(getComputedStyle(document.querySelector(".promotion-title")).lineHeight)+1'), true)
  }
  await browser.click('.promotion-action', 'أدخل الكوبون')
  assert.equal(await browser.evaluate('document.querySelector("#app").__vue_app__.config.globalProperties.$route.path'), '/cart')
  await browser.evaluate('offerFixture.items=[]')
  await browser.wait('!document.querySelector(".site-offers")')
  checks.push('Coupon display links to the existing cart code entry without exposing or inventing a code; empty input hides section')
  assert.deepEqual(browser.diagnostics, [])
  await writeFile(resolve(output, 'report.json'), JSON.stringify({ checks, responsive, transport: 'Controlled API fixtures; no production mutations' }, null, 2))
  console.log(JSON.stringify({ checks, responsive }))
} finally { browser.close() }
