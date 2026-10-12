import assert from 'node:assert/strict'
import { resolve } from 'node:path'
import { browserClient } from './browser-client.mjs'

// Transport fixtures isolate request counts from local database contents.
const origin = 'http://127.0.0.1:5176'
const branchIds = ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222']
const userId = '33333333-3333-4333-8333-333333333333'
const browser = await browserClient(9368, resolve('.performance-check'))
const requests = [], failures = []
const count = path => requests.filter(url => url.pathname === '/api/v1' + path).length
try {
  await browser.send('Network.enable')
  await browser.send('Network.setCacheDisabled', { cacheDisabled: true })
  await browser.send('Page.addScriptToEvaluateOnNewDocument', { source: `localStorage.setItem('shishli.selectedBranch', ${JSON.stringify(branchIds[0])}); if(location.pathname.startsWith('/admin')) localStorage.setItem('shishli_auth_token', 'fixture-performance-token'); else localStorage.removeItem('shishli_auth_token')` })
  browser.onEvent('Fetch.requestPaused', async ({ requestId, request }) => {
    try {
      const url = new URL(request.url)
      requests.push(url)
      let data = {}, responseCode = 200
      const path = url.pathname.replace('/api/v1', '')
      if (path === '/branches') {
        await browser.sleep(200)
        data = branchIds.map((id, i) => ({ id, name: 'Branch ' + i, is_active: true, supports_pickup: true, location: {} }))
      } else if (path === '/home') data = { page: { title: 'Home', sections: [] }, banners: [], active_offers: [], secondary_banners: [] }
      else if (path === '/menu') data = { categories: [], availability: {} }
      else if (path === '/categories' || path === '/gallery' || path === '/admin/staff') data = []
      else if (path === '/settings/public') data = { restaurant: { name: 'Shishli' }, ordering: {} }
      else if (path.endsWith('/status')) data = { is_open: true, accepts_orders: true }
      else if (path.endsWith('/business-hours')) data = { days: [], timezone: 'UTC' }
      else if (url.pathname === '/api/user') data = { uuid: userId, name: 'Performance admin' }
      else if (path === '/admin/me') data = { id: userId, is_active: true, roles: [], permissions: ['staff.view'] }
      else { responseCode = 404 }
      const payload = { success: responseCode === 200, data, meta: { current_page: 1, last_page: 1, total: 0, per_page: 15 } }
      await browser.send('Fetch.fulfillRequest', {
        requestId, responseCode,
        responseHeaders: [{ name: 'Content-Type', value: 'application/json' }, { name: 'Access-Control-Allow-Origin', value: origin }, { name: 'Access-Control-Allow-Headers', value: 'Authorization, Content-Type' }, { name: 'Access-Control-Allow-Methods', value: 'GET, OPTIONS' }],
        body: Buffer.from(JSON.stringify(payload)).toString('base64'),
      })
    } catch (error) { failures.push(error.message) }
  })
  await browser.send('Fetch.enable', { patterns: [{ urlPattern: '*://*/api/*', requestStage: 'Request' }] })
  await browser.go(origin, '/')
  await browser.wait('document.querySelector("#site-branch option[value]")')
  await browser.sleep(800)
  assert.equal(count('/branches'), 1, 'Shared branch list is loaded once')
  assert.equal(count('/home'), 1, 'Home CMS loads once after the saved branch is restored')
  assert.equal(count('/menu'), 1, 'Initial menu is not duplicated by branch/filter watchers')
  assert.equal(requests.filter(url => url.pathname.endsWith('/status')).length, 1, 'Only the selected branch status is fetched')
  assert.equal(requests.find(url => url.pathname.endsWith('/home')).searchParams.get('branch'), branchIds[0])
  assert.equal(await browser.evaluate(`performance.getEntriesByType('resource').some(entry => /AdminLayout.*\.(css|js)/.test(entry.name))`), false, 'Public visitors do not load the admin layout')
  const initial = await browser.evaluate(`({css: performance.getEntriesByType('resource').filter(e=>e.name.endsWith('.css')).map(e=>e.name.split('/').pop()), assets: performance.getEntriesByType('resource').filter(e=>e.name.endsWith('.webp')).map(e=>e.name.split('/').pop())})`)
  for (const width of [390, 768, 1440]) {
    await browser.send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width === 390 })
    assert.equal(await browser.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), true)
  }
  await browser.evaluate(`document.querySelector('#app').__vue_app__.config.globalProperties.$router.push('/menu')`)
  await browser.wait('document.querySelector("#product-search")')
  await browser.sleep(500)
  const beforeSearch = count('/menu')
  await browser.input('#product-search', 'c')
  await browser.input('#product-search', 'ch')
  await browser.input('#product-search', 'chicken')
  await browser.sleep(600)
  assert.equal(count('/menu') - beforeSearch, 1, 'Typing is debounced into one menu request')
  const beforeSwitch = count('/menu')
  await browser.input('#site-branch', branchIds[1])
  await browser.sleep(800)
  assert.equal(count('/menu') - beforeSwitch, 1, 'Changing branches fetches the menu once')
  await browser.evaluate(`localStorage.setItem('shishli_auth_token', 'fixture-performance-token')`)
  await browser.go(origin, '/admin/staff')
  await browser.wait('document.querySelector(".admin-layout .topbar")')
  assert.equal(await browser.evaluate(`performance.getEntriesByType('resource').some(entry => /AdminLayout.*\.css/.test(entry.name))`), true, 'Admin styles load on the admin route')
  assert.equal(await browser.evaluate(`getComputedStyle(document.querySelector('.admin-layout')).display`), 'block')
  await browser.evaluate(`document.querySelector('#app').__vue_app__.config.globalProperties.$router.push('/')`)
  await browser.wait('document.querySelector(".site-layout .hero")')
  for (const width of [390, 1440]) {
    await browser.send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width === 390 })
    assert.equal(await browser.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), true, 'Returning from admin preserves the public layout')
  }
  assert.deepEqual(failures, [])
  assert.deepEqual(browser.diagnostics, [])
  console.log(JSON.stringify({ passed: true, checks: ['initial request counts', 'selected branch only', 'debounced search', 'branch switching', 'lazy admin CSS/JS', 'responsive public/admin navigation'], initial }))
} finally { browser.close() }
