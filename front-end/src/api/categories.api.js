import { http, unwrap } from './http'
import { crud, publicRequest } from './catalogTransport'
export const categoriesApi = { ...crud('/admin/categories'),
  tree: () => http.get('/admin/categories/tree').then(unwrap),
  publicList: () => http.get('/categories', publicRequest).then(unwrap),
}
export function flattenCategories(nodes, depth = 0) {
  return nodes.flatMap(node => [{ ...node, label: '— '.repeat(depth) + node.name }, ...flattenCategories(node.children || [], depth + 1)])
}
