import assert from 'node:assert/strict'
import { readFile, writeFile, mkdir } from 'node:fs/promises'
const stage = process.env.PERF_STAGE || 'before', engines = ['mysql', 'sqlite'], results = []
for (const engine of engines) {
  const fixture = JSON.parse(await readFile('.performance-check/audit-' + engine + '.json', 'utf8'))
  const origin = engine === 'mysql' ? 'http://127.0.0.1:8019' : 'http://127.0.0.1:8020'
  await fetch(origin + '/api/v1/cart', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: 'Bearer ' + fixture.tokens.customer }, body: JSON.stringify({ branch_uuid: fixture.branch }) })
  // Keep the cart fixture identical for both engines and both stages.
  await fetch(origin + '/api/v1/cart/items', { method: 'DELETE', headers: { Accept: 'application/json', Authorization: 'Bearer ' + fixture.tokens.customer } })
  const paths = [['/api/v1/home?branch=' + fixture.branch], ['/api/v1/menu?branch=' + fixture.branch], ['/api/v1/menu/products/' + fixture.product + '?branch=' + fixture.branch], ['/api/v1/branches'], ['/api/v1/cart', 'customer'], ['/api/v1/addresses', 'customer'], ['/api/v1/orders/' + fixture.order + '/tracking', 'customer'], ['/api/v1/admin/dashboard', 'admin'], ['/api/v1/admin/orders', 'admin'], ['/api/v1/driver/orders', 'driver']]
  for (const [path, role] of paths) {
    const samples = []
    for (let i = 0; i < 7; i++) {
      const start = performance.now(), response = await fetch(origin + path, { headers: { Accept: 'application/json', ...(role ? { Authorization: 'Bearer ' + fixture.tokens[role] } : {}) } })
      const text = await response.text()
      assert.equal(response.status, 200, path + ':' + text.slice(0, 300))
      samples.push({ ms: performance.now() - start, bytes: Buffer.byteLength(text), timing: response.headers.get('server-timing') })
    }
    results.push({ engine, path: path.replace(fixture.branch, ':branch').replace(fixture.product, ':product').replace(fixture.order, ':order'), samples })
  }
}
await mkdir('../docs/performance', { recursive: true })
await writeFile('../docs/performance/' + stage + '-api.json', JSON.stringify(results, null, 2))
console.log('Recorded 140 real HTTP responses, 7 samples x 10 endpoints x MySQL/SQLite')
