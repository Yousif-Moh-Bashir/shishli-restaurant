import { http, unwrap } from './http'
const path = product => '/admin/products/' + encodeURIComponent(product) + '/images'
export const productImagesApi = {
  upload: (product, files, altText, primary) => {
    const body = new FormData()
    files.forEach(file => body.append('images[]', file))
    if (altText) body.append('alt_text', altText)
    body.append('is_primary', primary ? '1' : '0')
    return http.post(path(product), body).then(unwrap)
  },
  primary: (product, image) => http.patch(path(product) + '/' + encodeURIComponent(image) + '/primary').then(unwrap),
  remove: (product, image) => http.delete(path(product) + '/' + encodeURIComponent(image)).then(unwrap),
  reorder: (product, images) => http.patch(path(product) + '/reorder', { images: images.map((image, sort_order) => ({ id: image.id, sort_order })) }).then(unwrap),
}
