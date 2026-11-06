export const currency = value => new Intl.NumberFormat('ar-SA', { style: 'currency', currency: 'SAR', maximumFractionDigits: 2 }).format(Number(value || 0))
let financeTimezone = 'Asia/Riyadh'
export const setFinanceTimezone = value => { financeTimezone = value || 'Asia/Riyadh' }
export const today = () => {
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en', { timeZone: financeTimezone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date()).map(p => [p.type, p.value]))
  return `${parts.year}-${parts.month}-${parts.day}`
}
export function normalizeAmount(value) {
  return String(value ?? '').replace(/[\u0660-\u0669\u06f0-\u06f9]/g, digit => String(digit.charCodeAt(0) - (digit.charCodeAt(0) >= 0x06f0 ? 0x06f0 : 0x0660))).replace(/\u066b/g, '.')
}
export function normalizeAmountInput(event) {
  const value = normalizeAmount(event.target.value)
  event.target.value = value
  return value
}
export function totalAmounts(lines) {
  let total = 0n
  for (const line of lines) {
    const value = normalizeAmount(line.amount).trim() || '0'
    if (!/^\d{1,12}(\.\d{1,2})?$/.test(value)) return null
    const [whole, fraction = ''] = value.split('.')
    total += BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'))
  }
  return `${total / 100n}.${String(total % 100n).padStart(2, '0')}`
}
export function errorMessage(error) {
  const status = error.response?.status
  if (status === 401) return 'بيانات الدخول غير صحيحة أو انتهت صلاحية الجلسة.'
  if (status === 409) return 'تغيّر السجل أو تم اعتماده. أغلق النموذج وأعد تحميل القائمة قبل المحاولة.'
  if (status === 403) return 'ليس لديك صلاحية لتنفيذ هذه العملية.'
  if (status === 404) return 'السجل غير متاح أو خارج الفروع المسموحة لك.'
  if (status === 422) return 'راجع الحقول الموضحة وأدخل بيانات صحيحة.'
  if (status === 429) return 'محاولات كثيرة. انتظر قليلًا ثم حاول مجددًا.'
  return status ? 'تعذر إتمام العملية. حاول مجددًا.' : 'تعذر الاتصال بالخادم. تحقق من الاتصال ثم حاول مجددًا.'
}
