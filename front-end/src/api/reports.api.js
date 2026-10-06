import { http, unwrap } from './http'
import { reportDefinitions } from '../constants/reports'
const keys = ['date_from','date_to','branch_uuid','order_type','order_status','payment_method','group_by','sort_by','per_page','page']
const inventoryKeys = ['branch_uuid','product_uuid','category_uuid','search','low_stock','out_of_stock']
export function reportParams(values = {}, report = '') {
  return Object.fromEntries((report === 'inventory' ? inventoryKeys : keys).filter(key => values[key] !== '' && values[key] != null).map(key => [key, typeof values[key] === 'boolean' ? Number(values[key]) : values[key]]))
}
function definition(key) {
  const report = reportDefinitions.find(item => item.key === key)
  if (!report) throw new Error('INVALID_REPORT')
  return report
}
export async function csvError(cause) {
  if (cause.response?.data instanceof Blob) {
    try { cause.response.data = JSON.parse(await cause.response.data.text()) } catch { cause.response.data = null }
  }
  return cause
}
export function csvFilename(disposition) {
  const encoded = disposition?.match(/filename\*=UTF-8''([^;]+)/i)
  const plain = disposition?.match(/filename="([^"]+)"|filename=([^;]+)/i)
  let name
  try { name = encoded ? decodeURIComponent(encoded[1]) : plain?.[1] || plain?.[2]?.trim() } catch { /* Invalid server filename. */ }
  return name?.split(/[\\/]/).pop()?.replace(/[\u0000-\u001f]/g, '') || 'report.csv'
}
export const reportsApi = {
  async get(key, params, signal) {
    definition(key)
    const result = unwrap(await http.get('/admin/reports/' + key, { params: reportParams(params, key), signal }))
    if (!result || (key === 'inventory' ? !Object.hasOwn(result, 'tracked_products_count') : !result.period || !Array.isArray(result.data))) throw new Error('INVALID_REPORT_RESPONSE')
    return result
  },
  async export(key, params, signal) {
    if (!definition(key).export) throw new Error('INVALID_EXPORT')
    try {
      const response = await http.get('/admin/reports/' + key + '/export', { params: { ...reportParams(params, key), format: 'csv' }, responseType: 'blob', signal })
      if (!response.headers['content-type']?.toLowerCase().startsWith('text/csv')) throw new Error('INVALID_CSV_RESPONSE')
      return { blob: response.data, filename: csvFilename(response.headers['content-disposition']) }
    } catch (cause) { throw await csvError(cause) }
  },
}
