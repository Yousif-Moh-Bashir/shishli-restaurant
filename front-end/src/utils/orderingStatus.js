import { zonedDateTimeInput } from './formDates.js'

export function orderingResumeMessage(value, timeZone = 'Asia/Riyadh', now = Date.now()) {
  const date = new Date(value)
  if (!value || !Number.isFinite(date.getTime()) || date.getTime() <= now) return ''
  try {
    const day = zonedDateTimeInput(value, timeZone).slice(0, 10)
    const today = zonedDateTimeInput(new Date(now).toISOString(), timeZone).slice(0, 10)
    const [year, month, dayOfMonth] = today.split('-').map(Number)
    const tomorrow = new Date(Date.UTC(year, month - 1, dayOfMonth + 1)).toISOString().slice(0, 10)
    const label = day === today ? 'اليوم' : day === tomorrow ? 'غدًا' : new Intl.DateTimeFormat('ar-SA-u-ca-gregory', { dateStyle: 'full', timeZone }).format(date)
    const time = new Intl.DateTimeFormat('ar-SA', { hour: 'numeric', minute: '2-digit', timeZone }).format(date)
    return `سنعاود استقبال الطلبات ${label} الساعة ${time} (موعد متوقع).`
  } catch { return '' }
}
