import {safeContentUrl} from './contentUrl.js'
import {brandName} from './branding.js'
export const siteUrl = (import.meta.env?.VITE_SITE_URL || 'https://shishlifresh.com').replace(/\/+$/, '')
const defaultLogo = siteUrl + '/apple-touch-icon.png'
function restaurantLogo(path) {
  if (typeof path !== 'string' || !path || /[\u0000-\u0020\\]/.test(path) || path.split('/').some(part => part === '.' || part === '..')) return defaultLogo
  if (!/^https?:\/\//.test(path) && !/^[a-zA-Z0-9_/.-]+$/.test(path)) return defaultLogo
  const candidate = /^(?:https?:\/\/|\/)/.test(path) ? path : '/' + (path.startsWith('storage/') ? path : 'storage/' + path)
  const safe = safeContentUrl(candidate)
  if (!safe) return defaultLogo
  try {
    const url = new URL(safe, siteUrl + '/')
    return url.protocol === 'https:' || (url.protocol === 'http:' && url.origin === new URL(siteUrl).origin) ? url.href : defaultLogo
  } catch { return defaultLogo }
}
export const defaultDescription = 'اكتشف قائمة مطعم شيشلي فرش للدجاج التركي والشيش، وتصفح الأطباق والعروض والفروع واطلب وجبتك المفضلة.'
export const homeSeo = {
  title: brandName + ' | مشويات ودجاج تركي وكباب في تبوك',
  description: 'استمتع بأشهى المشويات والدجاج التركي والكباب في مطعم شيشلي فرش بتبوك. تصفح قائمة الطعام واطلب وجبتك المفضلة مباشرة من موقعنا.',
}
export const publicPages = {
  home: [homeSeo.title, homeSeo.description],
  menu: ['قائمة الطعام | مطعم شيشلي فرش', 'تصفح أطباق الدجاج التركي والشيش والمشويات والإضافات في قائمة مطعم شيشلي فرش.'],
  branches: ['فروعنا | مطعم شيشلي فرش', 'تعرف على فروع مطعم شيشلي فرش ومواقعها ووسائل التواصل معها، واختر الفرع المناسب لطلبك.'],
  offers: ['العروض | مطعم شيشلي فرش', 'اكتشف عروض مطعم شيشلي فرش الحالية وشروط الخصومات والكوبونات وتواريخ صلاحيتها.'],
  about: ['عن المطعم | مطعم شيشلي فرش', 'تعرف على مطعم شيشلي فرش للدجاج التركي والشيش وقصتنا.'],
  contact: ['تواصل معنا | مطعم شيشلي فرش', 'تواصل مع مطعم شيشلي فرش واطلع على بيانات الاتصال ومواقع فروعنا.'],
  gallery: ['معرض الصور | مطعم شيشلي فرش', 'شاهد صور مطعم شيشلي فرش وأطباقنا في معرض الصور.'],
  product: ['تفاصيل المنتج | مطعم شيشلي فرش', 'تعرف على تفاصيل هذا الطبق من قائمة مطعم شيشلي فرش.'],
}
export function canonicalUrl(path) {
  return siteUrl + '/' + String(path).split(/[?#]/)[0].replace(/^\/+|\/+$/g, '')
}
export function routeSeo(route) {
  const key = route.meta?.cmsKey || route.name
  const page = publicPages[key]
  const privateTitles = { login: 'تسجيل الدخول | مطعم شيشلي فرش', register: 'إنشاء حساب | مطعم شيشلي فرش', cart: 'سلة طلباتك | مطعم شيشلي فرش', checkout: 'إكمال الطلب | مطعم شيشلي فرش', 'order-success': 'تفاصيل الطلب | مطعم شيشلي فرش', 'track-order': 'تتبع الطلب | مطعم شيشلي فرش' }
  const fallback = String(route.name).startsWith('admin.') ? 'لوحة شيشلي' : 'مطعم شيشلي فرش'
  return { title: page?.[0] || route.meta?.title || privateTitles[route.name] || fallback, description: page?.[1] || defaultDescription, path: route.path, index: !!page }
}
export function cmsPageSeo(route, page) {
  const defaults = routeSeo(route)
  const isHome = (route.meta?.cmsKey || route.name) === 'home'
  return { ...defaults,
    title: isHome ? homeSeo.title : page.seo?.title || page.title || defaults.title,
    description: isHome ? homeSeo.description : page.seo?.description || defaults.description,
    image: page.seo?.og_image_url,
  }
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
  meta('og:site_name', brandName, true)
  meta('twitter:title', document.title)
  meta('twitter:description', description || defaultDescription)
  let safeImage
  try { const url = new URL(image, siteUrl); if (image && ['https:', 'http:'].includes(url.protocol)) safeImage = url.href } catch {}
  meta('og:image', safeImage, true)
  meta('og:image:alt', safeImage ? document.title : undefined, true)
  meta('twitter:card', safeImage ? 'summary_large_image' : 'summary')
  meta('twitter:image', safeImage)
  meta('twitter:image:alt', safeImage ? document.title : undefined)
}
export function restaurantSchema(settings, branches = []) {
  const restaurant = settings?.restaurant
  if (!restaurant?.name) return null
  const root = { '@type': 'Restaurant', '@id': siteUrl + '/#restaurant', name: restaurant.name, url: siteUrl + '/', hasMenu: siteUrl + '/menu' }
  root.logo = restaurantLogo(settings.branding?.logo_path)
  root.image = root.logo
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
let restaurantSchemaRevision = 0
export function setRestaurantSchema(schema) {
  const revision = ++restaurantSchemaRevision
  let element = document.getElementById('restaurant-jsonld')
  if (!schema) { element?.remove(); return }
  if (!element) { element = document.createElement('script'); element.id = 'restaurant-jsonld'; element.type = 'application/ld+json'; document.head.append(element) }
  const root = schema['@graph'][0], preferredLogo = root.logo
  // Keep a known public image in the markup until the configured image actually loads.
  const write = logo => {
    if (revision !== restaurantSchemaRevision) return
    const graph = [{ ...root, logo, image: logo }, ...schema['@graph'].slice(1)]
    element.textContent = JSON.stringify({ ...schema, '@graph': graph }).replace(/</g, '\\u003c')
  }
  write(defaultLogo)
  if (preferredLogo !== defaultLogo) {
    const image = new Image()
    image.referrerPolicy = 'no-referrer'
    image.onload = () => { if (image.naturalWidth > 0 && image.naturalHeight > 0) write(preferredLogo) }
    image.onerror = () => {} // The fallback already remains in the markup.
    image.src = preferredLogo
  }
}
