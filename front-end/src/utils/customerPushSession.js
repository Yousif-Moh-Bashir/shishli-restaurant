export const customerPushScope = '/customer-push/'
export const customerPushStorage = 'shishli.customerPush.binding'
export function readCustomerPushBinding() {
  try { return JSON.parse(localStorage.getItem(customerPushStorage) || 'null') } catch { return null }
}
export function saveCustomerPushBinding(value) {
  try { localStorage.setItem(customerPushStorage, JSON.stringify(value)) } catch { /* Device can still be disabled through its server record. */ }
}
export async function customerPushRegistration() {
  if (!navigator.serviceWorker) return null
  const reg = await navigator.serviceWorker.getRegistration(customerPushScope)
  // getRegistration may fall back to the staff worker at /. Never touch it.
  return reg?.scope === new URL(customerPushScope, location.origin).href ? reg : null
}
let operations = Promise.resolve()
export function withCustomerPushLock(action) {
  const run = () => navigator.locks ? navigator.locks.request('shishli-customer-push', action) : action()
  const result = operations.then(run, run)
  operations = result.catch(() => {})
  return result
}
export function clearCustomerPushSession(expectedId = null) {
  return withCustomerPushLock(async () => {
    if (expectedId && readCustomerPushBinding()?.id !== expectedId) return
    try { localStorage.removeItem(customerPushStorage) } catch { /* Storage may be disabled. */ }
    try {
      const reg = await customerPushRegistration()
      reg?.active?.postMessage({ type: 'customer-push-clear' })
      await (await reg?.pushManager.getSubscription())?.unsubscribe()
    } catch { /* Logout stays effective on the server; local binding is already cleared. */ }
  }).catch(() => {})
}
export async function activateCustomerWorker(reg) {
  if (reg.active?.state === 'activated') return reg
  const worker = reg.installing || reg.waiting || reg.active
  if (!worker) throw Error('WORKER_UNAVAILABLE')
  await new Promise((resolve, reject) => {
    const timer = setTimeout(() => { worker.removeEventListener('statechange', changed); reject(Error('WORKER_TIMEOUT')) }, 12000)
    function changed() { if (worker.state === 'activated') { clearTimeout(timer); worker.removeEventListener('statechange', changed); resolve() } }
    worker.addEventListener('statechange', changed); changed()
  })
  return reg
}
function workerRequest(reg, data) {
  return new Promise((resolve, reject) => {
    const channel = new MessageChannel()
    const timer = setTimeout(() => { channel.port1.close(); reject(Error('WORKER_BIND_TIMEOUT')) }, 5000)
    channel.port1.onmessage = event => {
      clearTimeout(timer); channel.port1.close()
      event.data?.ok ? resolve(event.data) : reject(Error('WORKER_BIND_FAILED'))
    }
    reg.active.postMessage(data, [channel.port2])
  })
}
export const bindCustomerWorker = (reg, id) => workerRequest(reg, { type: 'customer-push-bind', id })
export const customerWorkerStatus = reg => workerRequest(reg, { type: 'customer-push-status' })
