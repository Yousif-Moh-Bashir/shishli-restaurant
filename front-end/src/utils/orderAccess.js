// Keep access credentials and minimal order references, never customer data or snapshots.
const memory = new Map()
const summaries = new Map()
const key = id => 'shishli_order_access:' + id
const summaryKey = id => 'shishli_order_summary:' + id
function readSummary(id) {
  let value = summaries.get(id) || {}
  try { value = JSON.parse(localStorage.getItem(summaryKey(id)) || 'null') || value } catch { /* Use the in-memory reference. */ }
  return {
    number: typeof value.number === 'string' ? value.number.slice(0, 80) : '',
    placedAt: typeof value.placedAt === 'string' && Number.isFinite(Date.parse(value.placedAt)) ? value.placedAt : null,
    expiresAt: typeof value.expiresAt === 'string' && Number.isFinite(Date.parse(value.expiresAt)) ? value.expiresAt : null,
  }
}
export function saveOrderAccess(id, token, details = {}) {
  if (typeof token !== 'string' || token.length !== 64) return false
  memory.set(id, token)
  const previous = readSummary(id)
  const summary = {
    number: typeof details.number === 'string' ? details.number.slice(0, 80) : previous.number || '',
    placedAt: typeof details.placedAt === 'string' ? details.placedAt : previous.placedAt || new Date().toISOString(),
    expiresAt: typeof details.expiresAt === 'string' ? details.expiresAt : previous.expiresAt || null,
  }
  summaries.set(id, summary)
  let persisted = false
  try { sessionStorage.setItem(key(id), token); persisted = true } catch { /* Memory remains usable. */ }
  try { localStorage.setItem(key(id), token); persisted = true; localStorage.setItem(summaryKey(id), JSON.stringify(summary)) } catch { /* Fall back to this tab when durable storage is unavailable. */ }
  if (typeof window !== 'undefined') window.dispatchEvent(new Event('shishli:orders-saved'))
  return persisted
}
export function readOrderAccess(id) {
  const expiresAt = readSummary(id).expiresAt
  if (expiresAt && Date.parse(expiresAt) <= Date.now()) return null
  let token = memory.get(id)
  try { token ||= localStorage.getItem(key(id)) } catch { /* Try the old tab storage below. */ }
  try { token ||= sessionStorage.getItem(key(id)) } catch { /* Memory remains usable. */ }
  return typeof token === 'string' && token.length === 64 ? token : null
}
export function orderAccessIsPersistent(id) {
  try { if (localStorage.getItem(key(id))) return true } catch { /* Tab storage may still work. */ }
  try { return Boolean(sessionStorage.getItem(key(id))) } catch { return false }
}
export function readSavedOrders() {
  const ids = new Set(memory.keys())
  for (const storageName of ['localStorage', 'sessionStorage']) {
    try {
      const storage = globalThis[storageName]
      for (let index = 0; index < storage.length; index++) {
        const item = storage.key(index)
        if (item?.startsWith('shishli_order_access:')) ids.add(item.slice('shishli_order_access:'.length))
      }
    } catch { /* Storage can be disabled independently. */ }
  }
  return [...ids].filter(id => readOrderAccess(id)).map(id => ({ id, ...readSummary(id) }))
    .sort((a, b) => (Date.parse(b.placedAt) || 0) - (Date.parse(a.placedAt) || 0))
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
