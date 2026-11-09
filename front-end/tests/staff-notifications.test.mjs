import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import { PendingOrderAlerts, claimOrderReminder } from '../src/utils/pendingOrderAlerts.js'

const now = Date.parse('2026-11-08T10:00:00Z')
const row = (id, expires = now + 1800000) => ({ id, order_id: id, expires_at: new Date(expires).toISOString() })

test('baseline and expired records stay silent; only new pending events can repeat', () => {
  const alerts = new PendingOrderAlerts()
  assert.deepEqual(alerts.observe([row('old')], now), [])
  assert.deepEqual(alerts.observe([row('old'), row('new'), row('expired', now - 1)], now).map(item => item.id), ['new'])
  assert.deepEqual(alerts.due(now + 60000).map(item => item.id), ['new'])
  assert.deepEqual(alerts.observe([row('old')], now + 70000), [])
  assert.deepEqual(alerts.due(now + 70000), [])
})

test('successful status operation stops pending reminders immediately and session reset is silent', () => {
  const alerts = new PendingOrderAlerts()
  alerts.observe([], now)
  alerts.observe([row('new')], now)
  alerts.removeOrder('new')
  assert.deepEqual(alerts.due(now), [])
  alerts.reset()
  assert.deepEqual(alerts.observe([row('new')], now), [])
})

test('shared reminder claim prevents polling, push and tab duplicates until repeat interval', () => {
  const values = new Map(), storage = { getItem: key => values.get(key), setItem: (key, value) => values.set(key, value) }
  assert.equal(claimOrderReminder(storage, 'user:event', now, 60000), true)
  assert.equal(claimOrderReminder(storage, 'user:event', now, 60000), false)
  assert.equal(claimOrderReminder(storage, 'user:event', now + 59999, 60000), false)
  assert.equal(claimOrderReminder(storage, 'user:event', now + 60000, 60000), true)
  assert.equal(claimOrderReminder(storage, 'other:event', now, 60000), true)
  assert.equal(claimOrderReminder({ getItem() { throw Error('blocked') } }, 'key', now, 60000), false)
})

test('worker deduplicates visible notifications, ignores expired pushes and constrains navigation', async () => {
  const listeners = new Map(), notifications = [], opened = [], messages = []
  const self = {
    addEventListener: (name, fn) => listeners.set(name, fn), location: { origin: 'https://restaurant.test' },
    registration: {
      getNotifications: async ({ tag } = {}) => notifications.filter(item => !item.closed && (!tag || item.tag === tag)),
      showNotification: async (title, options) => notifications.push({ ...options, title, close() { this.closed = true } }),
    },
    clients: { matchAll: async () => [{ url: 'https://restaurant.test/admin', postMessage: message => messages.push(message), navigate: async url => opened.push(url), focus: async () => {} }], openWindow: async url => opened.push(url) },
  }
  vm.runInNewContext(await readFile(new URL('../public/sw.js', import.meta.url), 'utf8'), { self, URL, Date })
  const dispatch = async (name, data) => {
    let work
    listeners.get(name)({ ...data, waitUntil: promise => { work = promise } })
    await work
  }
  const id = '11111111-1111-4111-8111-111111111111'
  const data = { id: 'event', order_id: id, url: 'https://evil.test', expires_at: new Date(Date.now() + 60000).toISOString() }
  await dispatch('push', { data: { json: () => data } })
  await dispatch('push', { data: { json: () => data } })
  await dispatch('push', { data: { json: () => ({ ...data, id: 'expired', expires_at: '2020-01-01' }) } })
  assert.equal(notifications.length, 1)
  assert.equal(notifications[0].renotify, false)
  await dispatch('notificationclick', { notification: notifications[0] })
  assert.deepEqual(opened, ['https://restaurant.test/admin/orders/' + id])
  assert.equal(messages.length, 1)
  notifications[0].closed = false
  await dispatch('message', { source: { url: 'https://restaurant.test/admin' }, data: { type: 'staff-pending-orders', ids: [] } })
  assert.equal(notifications[0].closed, true)

  const diagnostic = { kind: 'staff-push-diagnostic', id, title: 'Diagnostic', body: 'No order', url: 'https://evil.test', expires_at: new Date(Date.now() + 60000).toISOString() }
  await dispatch('push', { data: { json: () => diagnostic } })
  await dispatch('push', { data: { json: () => diagnostic } })
  await dispatch('push', { data: { json: () => ({ ...diagnostic, id: 'invalid' }) } })
  assert.equal(notifications.length, 2)
  assert.equal(notifications[1].data.order_id, undefined)
  assert.equal(messages[1].type, 'staff-push-diagnostic')
  await dispatch('message', { source: { url: 'https://restaurant.test/admin' }, data: { type: 'staff-pending-orders', ids: [] } })
  assert.notEqual(notifications[1].closed, true)
  await dispatch('notificationclick', { notification: notifications[1] })
  assert.equal(opened.at(-1), 'https://restaurant.test/admin/push-diagnostics')
  notifications[1].closed = false
  await dispatch('message', { source: { url: 'https://restaurant.test/admin' }, data: { type: 'staff-clear-notifications' } })
  assert.equal(notifications[1].closed, true)
  await dispatch('push', { data: { json: () => ({ ...diagnostic, kind: 'staff-push-pilot', id: '22222222-2222-4222-8222-222222222222' }) } })
  await dispatch('push', { data: { json: () => ({ ...diagnostic, kind: 'staff-push-pilot', id: '22222222-2222-4222-8222-222222222222' }) } })
  assert.equal(notifications.length, 3)
  assert.equal(messages.at(-1).type, 'staff-push-pilot')
  await dispatch('notificationclick', { notification: notifications[2] })
  assert.equal(opened.at(-1), 'https://restaurant.test/admin/web-push-pilot')
})
