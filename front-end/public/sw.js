/* Notifications only. Never intercept requests or cache authenticated responses. */
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()))

async function claimPush(id, expiresAt) {
  if (typeof indexedDB === 'undefined') return true
  try {
    const db = await new Promise((resolve, reject) => {
      const open = indexedDB.open('shishli-staff-push', 1)
      open.onupgradeneeded = () => open.result.createObjectStore('delivered', { keyPath: 'id' })
      open.onsuccess = () => resolve(open.result)
      open.onerror = () => reject(open.error)
    })
    return await new Promise((resolve, reject) => {
      const transaction = db.transaction('delivered', 'readwrite'), store = transaction.objectStore('delivered')
      let claimed = false
      const request = store.get(id)
      request.onsuccess = () => {
        if (!request.result || request.result.expiresAt <= Date.now()) {
          store.put({ id, expiresAt }); claimed = true
        }
      }
      const cursor = store.openCursor()
      cursor.onsuccess = () => {
        const entry = cursor.result
        if (entry) { if (entry.value.expiresAt <= Date.now()) entry.delete(); entry.continue() }
      }
      transaction.oncomplete = () => { db.close(); resolve(claimed) }
      transaction.onerror = transaction.onabort = () => { db.close(); reject(transaction.error) }
    })
  } catch { return true }
}

self.addEventListener('push', event => {
  event.waitUntil((async () => {
    let data
    try { data = event.data?.json() } catch { return }
    const pilot = data?.kind === 'staff-push-pilot'
    const diagnostic = pilot || data?.kind === 'staff-push-diagnostic'
    if (!data?.id || (diagnostic ? !/^[a-f0-9-]{36}$/i.test(data.id) : !/^[a-f0-9-]{36}$/i.test(data.order_id || '')) || Date.parse(data.expires_at) <= Date.now() || !Number.isFinite(Date.parse(data.expires_at))) return
    const url = pilot ? '/admin/web-push-pilot' : diagnostic ? '/admin/push-diagnostics' : '/admin/orders/' + data.order_id
    const tag = (diagnostic ? 'shishli-push-test-' : 'shishli-order-') + data.id
    const existing = await self.registration.getNotifications({ tag })
    if (existing.length) return
    if (!await claimPush((diagnostic ? 'diagnostic:' : '') + data.id, Date.parse(data.expires_at))) return
    await self.registration.showNotification(data.title || 'طلب جديد في شيشلي', {
      body: data.body || 'طلب جديد بانتظار التأكيد', icon: '/apple-touch-icon.png', badge: '/favicon.png',
      tag, renotify: false, data: { id: data.id, kind: data.kind, order_id: data.order_id, url },
    })
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    for (const client of clients) client.postMessage({ type: pilot ? 'staff-push-pilot' : diagnostic ? 'staff-push-diagnostic' : 'staff-order-push', id: data.id })
  })())
})

self.addEventListener('notificationclick', event => {
  event.notification.close()
  event.waitUntil((async () => {
    const id = event.notification.data?.order_id
    const pilot = event.notification.data?.kind === 'staff-push-pilot'
    const diagnostic = pilot || event.notification.data?.kind === 'staff-push-diagnostic'
    if (!diagnostic && !/^[a-f0-9-]{36}$/i.test(id || '')) return
    const target = new URL(pilot ? '/admin/web-push-pilot' : diagnostic ? '/admin/push-diagnostics' : '/admin/orders/' + id, self.location.origin).href
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    const client = clients.find(item => new URL(item.url).origin === self.location.origin)
    if (client) { await client.navigate(target); await client.focus() }
    else await self.clients.openWindow(target)
  })())
})

self.addEventListener('message', event => {
  if (!event.source?.url || new URL(event.source.url).origin !== self.location.origin) return
  if (!['staff-clear-notifications', 'staff-pending-orders', 'staff-order-resolved'].includes(event.data?.type)) return
  event.waitUntil((async () => {
    const notifications = await self.registration.getNotifications()
    for (const notification of notifications) {
      if (!notification.tag.startsWith('shishli-order-') && !notification.tag.startsWith('shishli-push-test-')) continue
      if (notification.tag.startsWith('shishli-push-test-') && event.data.type !== 'staff-clear-notifications') continue
      if (event.data.type === 'staff-clear-notifications'
        || (event.data.type === 'staff-pending-orders' && !event.data.ids.includes(notification.data?.id))
        || (event.data.type === 'staff-order-resolved' && event.data.order_id === notification.data?.order_id)) notification.close()
    }
  })())
})
