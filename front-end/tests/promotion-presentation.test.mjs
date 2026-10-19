import test from 'node:test'
import assert from 'node:assert/strict'
import { promotionPresentation, promotionSummary } from '../src/utils/promotionPresentation.js'

const offer = overrides => ({ name: 'عرض شيشلي', description: 'نكهة تركية على الفحم.', type: 'automatic', application_type: 'cart', discount: { type: 'percentage', value: '12.50', maximum: null }, minimum_subtotal: '0.00', first_order_only: false, ends_at: null, ...overrides })

test('Percentage and decimal currency discounts use the existing Saudi currency format', () => {
  assert.equal(promotionPresentation(offer()).value, '12.5٪')
  assert.equal(promotionPresentation(offer({ discount: { type: 'fixed', value: '25.75' } })).value, '25.75 ريال')
  const fixedPrice = promotionPresentation(offer({ discount: { type: 'fixed_price', value: '49.00' } }))
  assert.equal(fixedPrice.value, '49 ريال')
  assert.equal(fixedPrice.valueLabel, 'سعر خاص')
})

test('Only supported conditions are presented, without inventing coupon codes or usage limits', () => {
  const source = offer({ first_order_only: true, application_type: 'product', minimum_subtotal: '80.00', discount: { type: 'percentage', value: '20.00', maximum: '30.00' } })
  const original = structuredClone(source)
  assert.deepEqual(promotionPresentation(source).conditions, ['لأول طلب بعد تسجيل الدخول', 'حد أدنى للطلب 80 ريال', 'خصم بحد أقصى 30 ريال', 'على أصناف مختارة'])
  assert.deepEqual(promotionPresentation(offer()).conditions, [])
  assert.deepEqual(promotionPresentation(offer({ application_type: 'category' })).conditions, ['على أقسام مختارة'])
  assert.deepEqual(source, original)
})

test('Coupon action opens the existing cart entry flow, automatic offers open menu', () => {
  const coupon = promotionPresentation(offer({ type: 'coupon' }))
  assert.equal(coupon.href, '/cart')
  assert.equal(coupon.action, 'أدخل الكوبون في السلة')
  assert.equal(Object.hasOwn(coupon, 'code'), false)
  assert.equal(promotionPresentation(offer()).href, '/menu')
})

test('Summaries remove repeated titles, sentences and exact conditions and stay concise', () => {
  assert.equal(promotionSummary('عرض اليوم', 'عرض اليوم. وجبة مميزة. وجبة مميزة. حد أدنى للطلب 80 ريال.', ['حد أدنى للطلب 80 ريال']), 'وجبة مميزة.')
  assert.equal(promotionSummary('العرض', 'العرض'), '')
  assert.equal(promotionSummary('العرض', null), '')
  const long = promotionSummary('العرض', 'مذاق تركي شهي '.repeat(40))
  assert.ok(long.length <= 140)
  assert.ok(long.endsWith('…'))
})

test('Optional expiry is preserved as supplied; invalid dates and values never render NaN', () => {
  assert.equal(promotionPresentation(offer({ ends_at: '2030-01-01T21:00:00Z' })).endsAt, '2030-01-01T21:00:00Z')
  assert.equal(promotionPresentation(offer({ ends_at: 'invalid' })).endsAt, null)
  for (const value of [undefined, null, '', 'bad', -1]) {
    assert.equal(promotionPresentation(offer({ discount: { type: 'fixed', value } })).value, 'عرض خاص')
  }
  assert.equal(promotionPresentation(offer({ discount: { type: 'fixed_price', value: '0.00' } })).value, '0 ريال')
})
