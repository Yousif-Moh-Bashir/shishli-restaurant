// Inputs explicitly edit UTC; untouched values retain original precision.
export const utcInput=value=>value?new Date(value).toISOString().slice(0,19):''
export const utcPayload=(value,original)=>value===utcInput(original)?original||null:value?new Date(value+'Z').toISOString():null

export function zonedDateTimeInput(value, timeZone = 'Asia/Riyadh') {
  if (!value) return ''
  try {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(value)).map(part => [part.type, part.value]))
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`
  } catch { return '' }
}
