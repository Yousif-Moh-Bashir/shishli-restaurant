import assert from 'node:assert/strict'
import { readFile, writeFile } from 'node:fs/promises'

const fixture = JSON.parse(await readFile('.performance-check/audit-mysql.json', 'utf8'))
assert.match(fixture.database, /^shishli_perf_[a-f0-9]{12}$/)
const journey = JSON.parse(await readFile('../docs/release/evidence/journey.json', 'utf8'))
assert.equal(journey.result, 'passed')
const id = journey.pages.find(path => path.startsWith('/account/orders/'))?.split('/').at(-1)
assert.ok(id)
async function call(path) {
  const response = await fetch('http://127.0.0.1:8019/api/v1' + path, {
    headers: { Accept: 'application/json', Authorization: 'Bearer ' + fixture.tokens.admin },
    signal: AbortSignal.timeout(20000),
  })
  assert.equal(response.status, 200, 'Unexpected report API status')
  return (await response.json()).data
}
const order = await call('/admin/orders/' + id)
assert.equal(order.status, 'completed')
assert.equal(order.payment.status, 'paid')
const report = await call('/admin/reports/orders?branch_uuid=' + order.branch.id + '&order_status=completed&payment_method=cash&per_page=100')
const row = report.data.find(item => item.order_uuid === id)
assert.ok(row, 'Current cash journey order appears in actual completed orders report')
assert.equal(row.status, 'completed')
assert.equal(row.total, order.pricing.total)
const evidence = { result: 'passed', checks: ['Current delivered cash journey order appears in the actual filtered orders report', 'Report total equals immutable admin order total'], order_uuid: id, total: row.total, period: report.period }
await writeFile('../docs/release/evidence/report-reconciliation.json', JSON.stringify(evidence, null, 2))
console.log(JSON.stringify(evidence))
