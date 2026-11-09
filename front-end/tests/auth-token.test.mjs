import assert from 'node:assert/strict'
import { test } from 'node:test'
import { readAuthSession, writeAuthToken } from '../src/utils/authToken.js'

function storage() {
  const values = new Map()
  return { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, String(value)), removeItem: key => values.delete(key), clear: () => values.clear() }
}
const key = 'shishli_auth_token'
const future = () => new Date(Date.now() + 60_000).toISOString()
function reset() { globalThis.localStorage = storage(); globalThis.sessionStorage = storage() }

test('admin token moves out of persistent storage and survives a page refresh only in session storage', () => {
  reset(); localStorage.setItem(key, 'legacy-admin')
  const expiresAt = future()
  assert.equal(writeAuthToken('admin', { sessionOnly: true, expiresAt }), true)
  assert.equal(localStorage.getItem(key), null)
  assert.deepEqual(readAuthSession(), { token: 'admin', expiresAt })
  sessionStorage.clear()
  assert.equal(readAuthSession().token, null)
})
test('customer token survives closing the browser session until its expiration', () => {
  reset(); const expiresAt = future()
  writeAuthToken('customer', { expiresAt }); sessionStorage.clear()
  assert.deepEqual(readAuthSession(), { token: 'customer', expiresAt })
})
test('expired or invalid expiry cannot restore a persisted token', () => {
  for (const sessionOnly of [true, false]) for (const expiresAt of ['invalid', new Date(Date.now() - 1).toISOString()]) {
    reset(); writeAuthToken('expired', { sessionOnly, expiresAt })
    assert.equal(readAuthSession().token, null)
    assert.equal((sessionOnly ? sessionStorage : localStorage).getItem(key), null)
  }
})
test('logout clears both stores and leaves finance and cart storage untouched', () => {
  reset(); localStorage.setItem(key, 'old'); sessionStorage.setItem(key, 'new')
  localStorage.setItem('shishli.cart.v1', 'cart'); sessionStorage.setItem('shishli_finance_token', 'finance')
  writeAuthToken(null)
  assert.equal(localStorage.getItem(key), null); assert.equal(sessionStorage.getItem(key), null)
  assert.equal(localStorage.getItem('shishli.cart.v1'), 'cart')
  assert.equal(sessionStorage.getItem('shishli_finance_token'), 'finance')
})
test('legacy token is available for backend validation and migration and driver token can retain no expiry', () => {
  reset(); localStorage.setItem(key, 'legacy')
  assert.deepEqual(readAuthSession(), { token: 'legacy', expiresAt: null })
  writeAuthToken('driver')
  assert.deepEqual(readAuthSession(), { token: 'driver', expiresAt: null })
})
test('unavailable browser storage fails safely', () => {
  const blocked = { getItem() { throw Error('blocked') }, removeItem() { throw Error('blocked') }, setItem() { throw Error('blocked') } }
  globalThis.localStorage = globalThis.sessionStorage = blocked
  assert.equal(readAuthSession().token, null)
  assert.equal(writeAuthToken('test', { sessionOnly: true }), false)
})
