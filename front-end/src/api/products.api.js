import { http, unwrap } from './http'
import { crud, publicRequest, cleanParams } from './catalogTransport'
export const productsApi = { ...crud('/admin/products'),
  bulk: (operation, product_uuids, value) => http.post('/admin/products/bulk/' + operation, { product_uuids, [operation === 'status' ? 'is_active' : 'is_available']: value }).then(unwrap),
}
export const menuApi = {
  publicProduct: (uuid, signal) => http.get('/products/' + encodeURIComponent(uuid), { ...publicRequest, signal }).then(unwrap),
  list: (params, signal) => http.get('/menu', { ...publicRequest, params: cleanParams(params), signal }).then(unwrap),
  product: (uuid, branch, signal) => http.get('/menu/products/' + encodeURIComponent(uuid), { ...publicRequest, params: { branch }, signal }).then(unwrap),
}
