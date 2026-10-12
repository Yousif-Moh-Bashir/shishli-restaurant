import assert from 'node:assert/strict'
import { readFile, writeFile } from 'node:fs/promises'

const fixture = JSON.parse(await readFile('.performance-check/audit-mysql.json', 'utf8'))
assert.match(fixture.database, /^shishli_perf_[a-f0-9]{12}$/)
const stage = process.env.RELEASE_STAGE || 'before'
assert.ok(['before', 'after'].includes(stage))
const routes = [
  ['/home?branch=' + fixture.branch], ['/menu?branch=' + fixture.branch],
  ['/menu/products/' + fixture.product + '?branch=' + fixture.branch], ['/branches'],
  ['/cart', 'customer'], ['/addresses', 'customer'],
  ['/orders/' + fixture.order + '/tracking', 'customer'],
  ['/admin/dashboard', 'admin'], ['/admin/orders', 'admin'], ['/driver/orders', 'driver'],
]
const report = { stage, repetitions: 7, engine: 'MySQL protocol / MariaDB', throttling: 'none; sequential localhost HTTP requests', endpoints: [] }
for (const [path, role] of routes) {
  const samples = []
  for (let index = 0; index < report.repetitions; index++) {
    const started = performance.now()
    const response = await fetch('http://127.0.0.1:8019/api/v1' + path, {
      headers: { Accept: 'application/json', ...(role ? { Authorization: 'Bearer ' + fixture.tokens[role] } : {}) },
      signal: AbortSignal.timeout(20000),
    })
    const body = await response.text()
    assert.equal(response.status, 200, 'Unexpected API status ' + response.status)
    samples.push({ ms: performance.now() - started, bytes: Buffer.byteLength(body), serverTiming: response.headers.get('server-timing') })
  }
  report.endpoints.push({ path: path.replaceAll(fixture.branch, ':branch').replaceAll(fixture.product, ':product').replaceAll(fixture.order, ':order'), samples })
}
await writeFile('../docs/release/evidence/' + stage + '-api.json', JSON.stringify(report, null, 2))
console.log('Recorded 70 real API responses without logging credentials or response bodies.')
