// Store credentials only, never customer data or an order response.
const memory = new Map()
const key = id => 'shishli_order_access:' + id
export function saveOrderAccess(id, token) {
  if (typeof token !== 'string' || token.length !== 64) return false
  memory.set(id, token)
  try { sessionStorage.setItem(key(id), token); return true } catch { return false }
}
export function readOrderAccess(id) {
  let token = memory.get(id)
  try { token ||= sessionStorage.getItem(key(id)) } catch { /* Memory remains usable. */ }
  return typeof token === 'string' && token.length === 64 ? token : null
}
export function orderAccessIsPersistent(id) {
  try { return Boolean(sessionStorage.getItem(key(id))) } catch { return false }
}
const attemptKey = 'shishli_checkout_attempt'
export function readCheckoutAttempts() {
  try {
    const entries = JSON.parse(sessionStorage.getItem(attemptKey) || '{}')
    return Object.fromEntries(Object.entries(entries || {}).filter(([scope, value]) => value && typeof value.uuid === 'string' && ['pickup', 'delivery'].includes(value.type)
      && (value.account === null ? scope === 'guest' : typeof value.account === 'string' && scope === 'account:' + value.account)))
  } catch { return {} }
}
export function persistCheckoutAttempts(value) {
  try { if (Object.keys(value).length) sessionStorage.setItem(attemptKey, JSON.stringify(value)); else sessionStorage.removeItem(attemptKey); return true }
  catch { return false }
}
