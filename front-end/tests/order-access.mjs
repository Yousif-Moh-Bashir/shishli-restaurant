import assert from 'node:assert/strict'

class Storage {
  values = new Map()
  get length() { return this.values.size }
  key(index) { return [...this.values.keys()][index] ?? null }
  getItem(key) { return this.values.get(key) ?? null }
  setItem(key, value) { this.values.set(key, String(value)) }
  removeItem(key) { this.values.delete(key) }
  clear() { this.values.clear() }
}
globalThis.localStorage = new Storage()
globalThis.sessionStorage = new Storage()
globalThis.window = new EventTarget()
let access = await import('../src/utils/orderAccess.js')
const id = '11111111-1111-4111-8111-111111111111'
const token = 'a'.repeat(64)
const expiresAt = new Date(Date.now() + 86400000).toISOString()
assert.equal(access.saveOrderAccess(id, token, { number: 'SH-100', placedAt: '2026-10-07T10:00:00Z', expiresAt }), true)
assert.equal(access.readSavedOrders()[0].number, 'SH-100')
assert.equal(Object.hasOwn(access.readSavedOrders()[0], 'token'), false, 'History exposes no access credentials')
assert.equal(Object.hasOwn(access.readSavedOrders()[0], 'customer'), false)
sessionStorage.clear()
access = await import('../src/utils/orderAccess.js?reopened')
assert.equal(access.readOrderAccess(id), token, 'A reopened tab restores guest access from durable storage')
assert.equal(access.orderAccessIsPersistent(id), true)
assert.equal(access.readSavedOrders().length, 1)
access.saveOrderAccess(id, token, { number: 'SH-100' })
assert.equal(access.readSavedOrders()[0].expiresAt, expiresAt, 'Updating the reference preserves expiry')
sessionStorage.setItem('shishli_order_access:legacy-order', 'b'.repeat(64))
assert.ok(access.readSavedOrders().some(order => order.id === 'legacy-order'), 'Existing tab credentials remain discoverable')
access.saveOrderAccess('expired', token, { expiresAt: new Date(Date.now() - 1000).toISOString() })
assert.equal(access.readOrderAccess('expired'), null)
assert.equal(access.readSavedOrders().some(order => order.id === 'expired'), false)
localStorage.setItem('shishli_order_access:corrupt', 'bad')
assert.equal(access.readSavedOrders().some(order => order.id === 'corrupt'), false)
localStorage.setItem('shishli_order_summary:' + id, 'not JSON')
assert.equal(access.readOrderAccess(id), token, 'Malformed reference data cannot destroy valid access')
const durable = localStorage
globalThis.localStorage = { get length() { throw Error('blocked') }, getItem() { throw Error('blocked') }, setItem() { throw Error('blocked') } }
assert.equal(access.saveOrderAccess('tab-only', token), true)
assert.equal(access.readOrderAccess('tab-only'), token)
globalThis.localStorage = durable
console.log('PASS: guest history, reopened tab persistence, credential privacy, metadata merge, legacy access, expiration, corrupt storage, and session fallback.')
