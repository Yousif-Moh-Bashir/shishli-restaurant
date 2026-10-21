import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createSSRApp, h } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createPinia, setActivePinia } from 'pinia'
import { exceptionPayload } from '../src/constants/deliveryExceptions.js'
import { isDriverOrderClosed, deliveryLabels } from '../src/constants/driverDelivery.js'
import { adminPermissionLabel, adminDomainLabel } from '../src/constants/adminPresentation.js'

const order = { id: 'f1b0ea68-ec62-4dbe-818e-2567881cf3bd', order_number: 'SH-0002', type: 'delivery',
  delivery_revision: 4, preparation_status: 'out_for_delivery', delivery_status: 'on_the_way',
  payment: { method: 'network', status: 'pending' }, pricing: { total: '57.00', currency: 'SAR' }, available_actions: ['collect_cash'] }
const key = '7e799076-7e60-4c86-ac37-419ff478a621'
const request = exceptionPayload(order, 'request_intervention', '  يحتاج تأكيد الشبكة  ', null, key)
assert.equal(request.operation_key, key)
assert.equal(request.expected_revision, 4)
assert.equal(request.reason, 'يحتاج تأكيد الشبكة')
assert.equal(Object.hasOwn(request, 'payment_status'), false)
assert.equal(Object.hasOwn(request, 'driver_uuid'), false)
assert.equal(exceptionPayload({ delivery_exception: { revision: 7 } }, 'reassign', 'نقل العهدة', 'target', key).expected_revision, 7)
assert.equal(exceptionPayload(order, 'reassign', 'نقل العهدة', 'target', key).driver_uuid, 'target')
for (const status of ['returned', 'released', 'delivered']) assert.equal(isDriverOrderClosed({ ...order, delivery_status: status }), true)
for (const status of ['on_the_way', 'failed', 'returning']) assert.equal(isDriverOrderClosed({ ...order, delivery_status: status }), false)
assert.equal(isDriverOrderClosed({ ...order, task_closed_at: '2026-10-21T10:00:00Z' }), true)
assert.ok(deliveryLabels.returned && deliveryLabels.returning && deliveryLabels.released)
assert.equal(adminDomainLabel('delivery_exceptions'), 'استثناءات التوصيل')
for (const permission of ['view', 'fail', 'return', 'release', 'cancel', 'resolve_custody']) {
  assert.notEqual(adminPermissionLabel('delivery_exceptions.' + permission), 'صلاحية غير معروفة')
}

const server = await createServer({ server: { middlewareMode: true }, appType: 'custom', logLevel: 'error' })
try {
  const { http } = await server.ssrLoadModule('/src/api/http.js')
  const { deliveryExceptionsApi } = await server.ssrLoadModule('/src/api/deliveryExceptions.api.js')
  const { paymentsApi } = await server.ssrLoadModule('/src/api/payments.api.js')
  const calls = []
  http.defaults.adapter = async config => {
    calls.push({ method: config.method, url: config.url, body: typeof config.data === 'string' ? JSON.parse(config.data) : config.data })
    return { status: 200, statusText: 'OK', headers: {}, config, data: { success: true, data: order } }
  }
  await deliveryExceptionsApi.intervention(order.id, request)
  await deliveryExceptionsApi.manage(order.id, { ...request, action: 'start_return' })
  await paymentsApi.collect(order.id, { network_verified: true })
  assert.match(calls[0].url, /\/driver\/orders\/.*\/intervention$/)
  assert.deepEqual(calls[0].body, request)
  assert.equal(calls[1].body.action, 'start_return')
  assert.deepEqual(calls[2].body, { network_verified: true })
  http.defaults.adapter = async () => { throw { response: { status: 409, data: { success: false, message: 'تغيرت المهمة' } } } }
  await assert.rejects(deliveryExceptionsApi.manage(order.id, request), error => error.response.status === 409)

  const pinia = createPinia(); setActivePinia(pinia)
  const { useAuthStore } = await server.ssrLoadModule('/src/stores/auth.js')
  const auth = useAuthStore(pinia)
  auth.token = 'test-only'; auth.user = { uuid: 'test' }; auth.adminProfile = { is_active: true, permissions: ['payments.collect_cash'] }
  async function render(path, props) {
    const { default: component } = await server.ssrLoadModule(path)
    const app = createSSRApp({ render: () => h(component, props) })
    app.use(pinia); app.provide('admin', { confirmAction: async () => true })
    return renderToString(app)
  }
  const network = await render('/src/components/payments/CashCollection.vue', { order })
  assert.match(network, /تأكيد دفع الشبكة/)
  assert.match(network, /type="checkbox"/)
  assert.match(network, /تحققت من نجاح العملية فعليًا/)
  const card = await render('/src/components/payments/CashCollection.vue', { order: { ...order, payment: { method: 'card', status: 'pending' }, available_actions: [] } })
  assert.doesNotMatch(card, /class="button button-primary cash-collect"/)
  auth.adminProfile = { is_active: true, permissions: [] }
  const denied = await render('/src/components/payments/CashCollection.vue', { order })
  assert.doesNotMatch(denied, /class="button button-primary cash-collect"/)
  const intervention = await render('/src/components/orders/DriverIntervention.vue', { order: { ...order, can_request_intervention: true } })
  assert.match(intervention, /طلب تدخل الإدارة/)
  const pending = await render('/src/components/orders/DriverIntervention.vue', { order: { ...order, intervention_requested_at: '2026-10-21T10:00:00Z' } })
  assert.match(pending, /تم تسجيل طلب تدخل الإدارة/)
  assert.doesNotMatch(pending, /<button/)
  const returned = await render('/src/components/orders/OrderLifecycleSummary.vue', { status: 'out_for_delivery', deliveryStatus: 'returned' })
  assert.match(returned, /لم يُسجل تسليم للعميل/)
  const actions = await render('/src/components/orders/DeliveryExceptionActions.vue', { order: { ...order, delivery_exception: { actions: ['confirm_return'], revision: 5, financial_review_required: true } } })
  assert.match(actions, /تأكيد استلام المطعم/)
  assert.match(actions, /لم يُنفذ استرداد تلقائي/)
  assert.doesNotMatch(actions, /value="reassign"/)
  const closed = await render('/src/components/orders/DeliveryExceptionActions.vue', { order: { ...order, delivery_exception: { actions: [], task_closed_at: '2026-10-21T10:00:00Z' } } })
  assert.match(closed, /لا توجد إجراءات استثنائية/)
  assert.match(closed, /أي عهدة نقدية تبقى/)
  console.log('Delivery exceptions: payload, API transport, permissions, network confirmation, intervention and custody UI passed (SSR and transport fixtures; no live API).')
} finally { await server.close() }
