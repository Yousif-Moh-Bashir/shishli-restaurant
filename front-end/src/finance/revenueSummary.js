function amountText(value) {
  const text = String(value)
  if (!/^\d+(?:\.\d{1,2})?$/.test(text)) throw new Error('INVALID_REVENUE_AMOUNT')
  const [whole, fraction = ''] = text.split('.')
  const cents = fraction.padEnd(2, '0')
  return BigInt(whole).toString() + (cents === '00' ? '' : `.${cents}`)
}
const label = value => String(value || '').replace(/[\r\n\t]+/g, ' ').trim()

export function revenueSummary(record, { includeBranch = false } = {}) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(record.date)) throw new Error('INVALID_REVENUE_DATE')
  const [year, month, day] = record.date.split('-')
  const lines = [`حساب اليوم ${day}/${month}/${year}`]
  if (includeBranch && record.branch?.name) lines.push(`الفرع: ${label(record.branch.name)}`)
  for (const line of record.lines) {
    const amount = amountText(line.amount)
    if (amount !== '0') lines.push(`${label(line.source.name)} ${amount}`)
  }
  lines.push(`المجموع ${amountText(record.total)}`)
  if (record.status === 'draft') lines.push('الحالة: مسودة — لم يتم اعتماد الإقفال بعد.')
  return lines.join('\n')
}
