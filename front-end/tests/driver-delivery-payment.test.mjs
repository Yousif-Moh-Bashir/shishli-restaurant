import assert from 'node:assert/strict'
import { test } from 'node:test'
import { deliveryPaymentMessage } from '../src/constants/driverDelivery.js'

const unpaid = {
  delivery_status: 'on_the_way', preparation_status: 'out_for_delivery',
  payment_method: 'cash', payment_status: 'pending', available_actions: ['collect_payment', 'failed'],
}

test('receipt confirmation is independent of customer payment choice and coordinates', () => {
  const message = deliveryPaymentMessage(unpaid)
  assert.match(message, /أكّد تحصيل المبلغ/)
  assert.match(message, /طريقة الدفع لا تمنع التحصيل/)
  assert.equal(deliveryPaymentMessage({ ...unpaid, payment_method: 'network' }), message)
  assert.equal(deliveryPaymentMessage({ ...unpaid, address: { location: { latitude: 28, longitude: 36 } } }), message)
})

test('successful collection removes the payment blocker on the next server response', () => {
  assert.equal(deliveryPaymentMessage({ ...unpaid, payment_status: 'paid', available_actions: ['delivered', 'failed'] }), '')
})

test('server-authorized delivery is never replaced by a payment blocker', () => {
  assert.equal(deliveryPaymentMessage({ ...unpaid, available_actions: ['delivered'] }), '')
})

test('noncash pending payment directs the driver to the branch instead of cash collection', () => {
  const message = deliveryPaymentMessage({ ...unpaid, payment_method: 'card', available_actions: ['failed'] })
  assert.match(message, /إدارة الفرع/)
  assert.doesNotMatch(message, /سجّل استلام المبلغ النقدي/)
})

test('cash collection is not offered when the server does not permit it', () => {
  assert.match(deliveryPaymentMessage({ ...unpaid, available_actions: ['failed'] }), /إدارة الفرع/)
  assert.match(deliveryPaymentMessage({ ...unpaid, payment_status: 'refunded' }), /إدارة الفرع/)
})

test('closed and earlier delivery stages show no completion blocker', () => {
  for (const delivery_status of ['assigned', 'picked_up', 'failed', 'delivered']) {
    assert.equal(deliveryPaymentMessage({ ...unpaid, delivery_status }), '')
  }
  for (const preparation_status of ['completed', 'cancelled']) {
    assert.equal(deliveryPaymentMessage({ ...unpaid, preparation_status }), '')
  }
  assert.equal(deliveryPaymentMessage(null), '')
})
