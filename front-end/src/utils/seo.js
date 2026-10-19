export const siteUrl = (import.meta.env?.VITE_SITE_URL || 'https://shishlifresh.com').replace(/\/+$/, '')
export const defaultDescription = 'اكتشف قائمة مطعم شيشلي للدجاج التركي والشيش، وتصفح الأطباق والعروض والفروع واطلب وجبتك المفضلة.'
export const publicPages = {
  home: ['مطعم شيشلي | الدجاج التركي والشيش', defaultDescription],
  menu: ['قائمة الطعام | مطعم شيشلي', 'تصفح أطباق الدجاج التركي والشيش والمشويات والإضافات في قائمة مطعم شيشلي.'],
  branches: ['فروعنا | مطعم شيشلي', 'تعرف على فروع مطعم شيشلي ومواقعها ووسائل التواصل معها، واختر الفرع المناسب لطلبك.'],
  offers: ['عروض شيشلي | مطعم شيشلي', 'اكتشف عروض مطعم شيشلي الحالية وشروط الخصومات والكوبونات وتواريخ صلاحيتها.'],
  about: ['عن شيشلي | مطعم شيشلي', 'تعرف على مطعم شيشلي للدجاج التركي والشيش وقصتنا.'],
  contact: ['تواصل معنا | مطعم شيشلي', 'تواصل مع مطعم شيشلي واطلع على بيانات الاتصال ومواقع فروعنا.'],
  gallery: ['معرض الصور | مطعم شيشلي', 'شاهد صور مطعم شيشلي وأطباقنا في معرض الصور.'],
  product: ['تفاصيل المنتج | مطعم شيشلي', 'تعرف على تفاصيل هذا الطبق من قائمة مطعم شيشلي.'],
}
export function canonicalUrl(path) {
  return siteUrl + '/' + String(path).split(/[?#]/)[0].replace(/^\/+|\/+$/g, '')
}
export function routeSeo(route) {
  const key = route.meta?.cmsKey || route.name
  const page = publicPages[key]
  const privateTitles = { login: 'تسجيل الدخول | مطعم شيشلي', register: 'إنشاء حساب | مطعم شيشلي', cart: 'سلة طلباتك | مطعم شيشلي', checkout: 'إكمال الطلب | مطعم شيشلي', 'order-success': 'تفاصيل الطلب | مطعم شيشلي', 'track-order': 'تتبع الطلب | مطعم شيشلي' }
  const fallback = String(route.name).startsWith('admin.') ? 'لوحة شيشلي' : 'مطعم شيشلي'
  return { title: page?.[0] || route.meta?.title || privateTitles[route.name] || fallback, description: page?.[1] || defaultDescription, path: route.path, index: !!page }
}
function meta(name, content, property = false) {
  const attribute = property ? 'property' : 'name'
  let element = document.head.querySelector(`meta[${attribute}="${name}"]`)
  if (!content) { element?.remove(); return }
  if (!element) { element = document.createElement('meta'); element.setAttribute(attribute, name); document.head.append(element) }
  element.content = content
}
export function setPageSeo({ title, description = defaultDescription, path = location.pathname, image, index = true }) {
  document.title = title || publicPages.home[0]
  meta('description', description || defaultDescription)
  meta('robots', index ? 'index, follow' : 'noindex, follow')
  let canonical = document.head.querySelector('link[rel="canonical"]')
  if (!canonical) { canonical = document.createElement('link'); canonical.rel = 'canonical'; document.head.append(canonical) }
  canonical.href = canonicalUrl(path)
  meta('og:title', document.title, true)
  meta('og:description', description || defaultDescription, true)
  meta('og:url', canonical.href, true)
  meta('og:type', 'website', true)
  meta('og:locale', 'ar_SA', true)
  meta('og:site_name', 'مطعم شيشلي', true)
  let safeImage
  try { const url = new URL(image, siteUrl); if (image && ['https:', 'http:'].includes(url.protocol)) safeImage = url.href } catch {}
  meta('og:image', safeImage, true)
  meta('og:image:alt', safeImage ? document.title : undefined, true)
}
export function restaurantSchema(settings, branches = []) {
  const restaurant = settings?.restaurant
  if (!restaurant?.name) return null
  const root = { '@type': 'Restaurant', '@id': siteUrl + '/#restaurant', name: restaurant.name, url: siteUrl + '/', hasMenu: siteUrl + '/menu' }
  if (restaurant.phone) root.telephone = restaurant.phone
  if (restaurant.email) root.email = restaurant.email
  if (restaurant.city || restaurant.country) {
    root.address = { '@type': 'PostalAddress' }
    if (restaurant.city) root.address.addressLocality = restaurant.city
    if (restaurant.country) root.address.addressCountry = restaurant.country
  }
  const locations = branches.filter(branch => branch.is_active).map(branch => {
    const data = { '@type': 'Restaurant', '@id': siteUrl + '/branches#' + branch.id, name: restaurant.name + ' - ' + branch.name, parentOrganization: { '@id': root['@id'] }, url: siteUrl + '/branches', hasMenu: root.hasMenu }
    if (branch.contact?.phone) data.telephone = branch.contact.phone
    const location = branch.location || {}, address = { '@type': 'PostalAddress' }
    if (location.address) address.streetAddress = location.address
    if (location.city) address.addressLocality = location.city
    if (restaurant.country) address.addressCountry = restaurant.country
    if (Object.keys(address).length > 1) data.address = address
    if (location.latitude != null && location.longitude != null && Number.isFinite(Number(location.latitude)) && Number.isFinite(Number(location.longitude))) data.geo = { '@type': 'GeoCoordinates', latitude: Number(location.latitude), longitude: Number(location.longitude) }
    return data
  })
  return { '@context': 'https://schema.org', '@graph': [root, ...locations] }
}
export function setRestaurantSchema(schema) {
  let element = document.getElementById('restaurant-jsonld')
  if (!schema) { element?.remove(); return }
  if (!element) { element = document.createElement('script'); element.id = 'restaurant-jsonld'; element.type = 'application/ld+json'; document.head.append(element) }
  element.textContent = JSON.stringify(schema).replace(/</g, '\\u003c')
}
