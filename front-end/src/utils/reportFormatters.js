import { getOrderStatusLabel } from '../constants/orderStatus'
import { getPaymentMethodLabel, getPaymentStatusLabel } from './paymentStatus'
import { formatDateTime } from './formatters'
export const reportNumber = value => value == null || !Number.isFinite(Number(value)) ? 'غير متاح' : new Intl.NumberFormat('ar-SA', { numberingSystem: 'latn', maximumFractionDigits: 2 }).format(Number(value))
export function reportDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return 'غير متاح'
  return new Intl.DateTimeFormat('ar-SA', { calendar: 'gregory', numberingSystem: 'latn', timeZone: 'UTC', year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(value + 'T12:00:00Z'))
}
export function reportValue(value, type = 'text', context = {}) {
  if (value == null || value === '') return type === 'text' ? 'غير محدد' : 'غير متاح'
  if (type === 'money') {
    if (!context.currency || !Number.isFinite(Number(value))) return 'غير متاح'
    try { return new Intl.NumberFormat('ar-SA', { style: 'currency', currency: context.currency, numberingSystem: 'latn' }).format(Number(value)) } catch { return 'غير متاح' }
  }
  if (type === 'number') return reportNumber(value)
  if (type === 'percent') return reportNumber(value) + '%'
  if (type === 'date') return reportDate(value)
  if (type === 'datetime') return context.timezone ? formatDateTime(/Z$|[+-]\d\d:\d\d$/.test(value) ? value : value.replace(' ', 'T') + 'Z', context.timezone) : 'غير متاح'
  if (type === 'orderStatus') return getOrderStatusLabel(value)
  if (type === 'orderType') return ({ delivery: 'توصيل', pickup: 'استلام' })[value] || value
  if (type === 'method') return getPaymentMethodLabel(value)
  if (type === 'paymentStatus') return getPaymentStatusLabel(value)
  return String(value)
}
