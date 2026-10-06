import { http, unwrap } from './http'
import { crud } from './catalogTransport'
export const optionGroupsApi = crud('/admin/option-groups')
const valuesPath = group => '/admin/option-groups/' + encodeURIComponent(group) + '/values'
export const optionValuesApi = {
  create: (group, data) => http.post(valuesPath(group), data).then(unwrap),
  update: (group, value, data) => http.patch(valuesPath(group) + '/' + encodeURIComponent(value), data).then(unwrap),
  remove: (group, value) => http.delete(valuesPath(group) + '/' + encodeURIComponent(value)).then(unwrap),
}
const assignmentPath = product => '/admin/products/' + encodeURIComponent(product) + '/option-groups'
export const productOptionsApi = {
  list: product => http.get(assignmentPath(product)).then(unwrap),
  attach: (product, data) => http.post(assignmentPath(product), data).then(unwrap),
  update: (product, group, data) => http.patch(assignmentPath(product) + '/' + encodeURIComponent(group), data).then(unwrap),
  detach: (product, group) => http.delete(assignmentPath(product) + '/' + encodeURIComponent(group)).then(unwrap),
}
