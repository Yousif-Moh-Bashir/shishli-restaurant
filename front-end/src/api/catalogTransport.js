import { http, unwrap } from './http'
export const cleanParams = params => Object.fromEntries(Object.entries(params || {}).filter(([, value]) => value !== '' && value != null))
export function page(response) {
  unwrap(response)
  const body = response.data
  if (!Array.isArray(body.data) || !body.meta || !body.links) throw new Error('INVALID_PAGINATION')
  return body
}
export function crud(path,methods=['list','show','create','update','remove']) {
  const operations={
    list: (params, signal) => http.get(path, { params: cleanParams(params), signal }).then(page),
    show: id => http.get(path + '/' + encodeURIComponent(id)).then(unwrap),
    create: data => http.post(path, data).then(unwrap),
    update: (id, data) => http.patch(path + '/' + encodeURIComponent(id), data).then(unwrap),
    remove: id => http.delete(path + '/' + encodeURIComponent(id)).then(unwrap),
  }
  return Object.fromEntries(Object.entries(operations).filter(([name])=>methods.includes(name)))
}
export const publicRequest = { skipAuth: true, skipSessionExpiry: true }
