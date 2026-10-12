export function cleanPhone(value) {
  return String(value ?? '').trim()
    .replace(/[٠-٩]/g, digit => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)))
    .replace(/[۰-۹]/g, digit => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)))
    .replace(/[\s()-]/g, '').replace(/^00/, '+')
}

export function localPhone(value) {
  const phone = cleanPhone(value)
  return /^(?:\+966|966)5[0-9]{8}$/.test(phone) ? '0' + phone.replace(/^\+?966/, '') : phone
}

export function internationalPhone(value) {
  const phone = cleanPhone(value)
  if (/^05[0-9]{8}$/.test(phone)) return '+966' + phone.slice(1)
  if (/^9665[0-9]{8}$/.test(phone)) return '+' + phone
  return phone
}

// Only explicit phone fields in plain API payloads are converted. Files, notes,
// passwords and other values retain their original contents.
export function phoneRequest(value) {
  if (Array.isArray(value)) return value.map(phoneRequest)
  if (!value || Object.getPrototypeOf(value) !== Object.prototype) return value
  return Object.fromEntries(Object.entries(value).map(([key, item]) => [key,
    ['phone', 'whatsapp', 'restaurant.phone', 'restaurant.whatsapp'].includes(key) && typeof item === 'string'
      ? internationalPhone(item) : phoneRequest(item),
  ]))
}
