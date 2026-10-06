const currencyFormatter = new Intl.NumberFormat('ar-SA', { numberingSystem: 'latn' })

export function formatCurrency(value) {
  return `${currencyFormatter.format(value)} ريال`
}

// config/business.php defaults to Asia/Riyadh. API settings can supply a
// different business timezone at integration; never use browser-local time.
export function formatDateTime(value, timeZone = 'Asia/Riyadh') {
  if (!value) return 'غير متاح'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'غير متاح'
  return new Intl.DateTimeFormat('ar-SA', {
    calendar: 'gregory', numberingSystem: 'latn', timeZone,
    year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
  }).format(date)
}

export function formatDate(value, { dateStyle = 'short', timeZone = 'Asia/Riyadh' } = {}) {
  const date = new Date(value)
  if (!value || Number.isNaN(date.getTime())) return 'غير متاح'
  return new Intl.DateTimeFormat('ar-SA', { calendar: 'gregory', dateStyle, timeZone }).format(date)
}
