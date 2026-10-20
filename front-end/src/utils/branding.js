// Approved identity defaults; saved restaurant settings remain authoritative.
export const brandName = 'مطعم شيشلي فرش'
export const brandTagline = 'شيشلي فرش — نكهة المشويات التركية الأصيلة'
export function restaurantDisplayName(settings) {
  const name = settings?.restaurant?.name
  return typeof name === 'string' && name.trim() ? name : brandName
}
