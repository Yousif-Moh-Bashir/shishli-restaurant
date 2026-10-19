import { formatCurrency } from './formatters.js'

const number = new Intl.NumberFormat('ar-SA', { numberingSystem: 'latn', maximumFractionDigits: 2 })
const cleanText = value => String(value ?? '').replace(/\s+/gu, ' ').trim()
const comparableText = value => cleanText(value).replace(/[.!؟?]+$/u, '').toLocaleLowerCase('ar')

export function promotionSummary(name, description, conditions = []) {
  const seen = new Set([name, ...conditions].map(comparableText))
  const sentences = cleanText(description).split(/(?<=[.!؟?])\s+/u).filter(sentence => {
    const key = comparableText(sentence)
    if (!key || seen.has(key)) return false
    seen.add(key)
    return true
  })
  const summary = sentences.join(' ')
  if (summary.length <= 140) return summary
  const excerpt = summary.slice(0, 137)
  const boundary = excerpt.lastIndexOf(' ')
  return (boundary > 90 ? excerpt.slice(0, boundary) : excerpt) + '…'
}

export function promotionPresentation(offer) {
  const discount = offer.discount || {}
  const validValue = discount.value !== null && discount.value !== undefined && discount.value !== '' && Number.isFinite(Number(discount.value)) && Number(discount.value) >= 0
  const conditions = []
  if (offer.first_order_only) conditions.push('لأول طلب بعد تسجيل الدخول')
  if (Number(offer.minimum_subtotal) > 0) conditions.push('حد أدنى للطلب ' + formatCurrency(offer.minimum_subtotal))
  if (Number(discount.maximum) > 0) conditions.push('خصم بحد أقصى ' + formatCurrency(discount.maximum))
  if (offer.application_type === 'product') conditions.push('على أصناف مختارة')
  if (offer.application_type === 'category') conditions.push('على أقسام مختارة')
  const knownType = ['percentage', 'fixed', 'fixed_price'].includes(discount.type)
  const currency = validValue && knownType && discount.type !== 'percentage'
  const value = validValue && knownType ? discount.type === 'percentage' ? number.format(discount.value) + '٪' : formatCurrency(discount.value) : 'عرض خاص'

  return {
    name: cleanText(offer.name),
    summary: promotionSummary(offer.name, offer.description, conditions),
    value,
    amount: currency ? number.format(discount.value) : value,
    currency,
    valueLabel: validValue && knownType ? discount.type === 'fixed_price' ? 'سعر خاص' : 'خصم' : 'عروض شيشلي',
    conditions,
    endsAt: offer.ends_at && Number.isFinite(new Date(offer.ends_at).getTime()) ? offer.ends_at : null,
    coupon: offer.type === 'coupon',
    href: offer.type === 'coupon' ? '/cart' : '/menu',
    action: offer.type === 'coupon' ? 'أدخل الكوبون في السلة' : 'اختر أطباقك',
  }
}
