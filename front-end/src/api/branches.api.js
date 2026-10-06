import { http, unwrap } from './http'
import { crud, page, cleanParams, publicRequest } from './catalogTransport'
export const branchesApi = { ...crud('/admin/branches'),
  publicList: () => http.get('/branches', publicRequest).then(unwrap),
  publicShow: id => http.get('/branches/'+encodeURIComponent(id),publicRequest).then(unwrap),
}
const path = branch => '/admin/branches/' + encodeURIComponent(branch) + '/products'
export const branchProductsApi = {
  list: (branch, params, signal) => http.get(path(branch), { params: cleanParams(params), signal }).then(page),
  attach: (branch, data) => http.post(path(branch), data).then(unwrap),
  update: (branch, product, data) => http.patch(path(branch) + '/' + encodeURIComponent(product), data).then(unwrap),
  detach: (branch, product) => http.delete(path(branch) + '/' + encodeURIComponent(product)).then(unwrap),
  bulk: (branch, products) => http.post(path(branch) + '/bulk', { products }).then(unwrap),
}
