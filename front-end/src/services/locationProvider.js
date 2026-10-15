import { validLocation } from '../utils/location.js'

const env = import.meta.env || {}
export const mapProvider = {
  tileUrl: env.VITE_MAP_TILE_URL || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  attribution: env.VITE_MAP_ATTRIBUTION || '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
}

function searchBounds(center) {
  if (!validLocation(center?.latitude, center?.longitude)) return null
  // Search viewport only; delivery zones/fees remain entirely in Laravel.
  const latitude = Number(center.latitude), longitude = Number(center.longitude)
  const latitudeDelta = 30 / 111.32, longitudeDelta = 30 / (111.32 * Math.max(0.1, Math.cos(latitude * Math.PI / 180)))
  return [Math.max(-180,longitude-longitudeDelta),Math.max(-90,latitude-latitudeDelta),Math.min(180,longitude+longitudeDelta),Math.min(90,latitude+latitudeDelta)]
}
function normalizedCity(value) {
  const text=String(value||'').trim().toLowerCase().replace(/^مدينة\s+/u,'')
  return ['tabuk','tabouk','تبوك'].includes(text)?'تبوك':text
}

export function normalizeGeocodingResult(result) {
  const address = result?.address || {}
  return {
    city: address.city || address.town || address.village || address.municipality || '',
    district: address.suburb || address.neighbourhood || address.quarter || address.city_district || '',
    street: address.road || address.pedestrian || address.residential || '',
    postalCode: address.postcode || '',
    formattedAddress: result?.display_name || '',
  }
}

// Submit-only search; public Nominatim must never receive autocomplete traffic.
// The default same-origin Nginx proxy also enforces an application-wide quota.
// Custom direct endpoints must provide their own application-wide limiter.
export function createNominatimProvider({ reverseUrl, searchUrl, searchFormat = 'nominatim', fetcher = (...args) => fetch(...args), interval = 1100 } = {}) {
  const reverseEndpoint = reverseUrl || env.VITE_GEOCODING_REVERSE_URL || '/map-geocoding/reverse'
  const searchEndpoint = searchUrl || env.VITE_GEOCODING_SEARCH_URL || '/map-geocoding/search'
  const cache = new Map()
  let queue = Promise.resolve(), lastRequestAt = 0
  function check(signal) { if (signal?.aborted) throw new DOMException('Aborted', 'AbortError') }
  function delay(ms, signal) {
    return new Promise((resolve, reject) => {
      check(signal)
      const abort = () => { clearTimeout(timer); reject(new DOMException('Aborted', 'AbortError')) }
      const timer = setTimeout(() => { signal?.removeEventListener('abort', abort); resolve() }, ms)
      signal?.addEventListener('abort', abort, { once: true })
    })
  }
  async function request(endpoint, params, signal) {
    check(signal)
    if (endpoint === 'disabled') throw Error('GEOCODING_DISABLED')
    const url = new URL(endpoint, globalThis.window?.location?.origin || 'http://localhost')
    url.search = new URLSearchParams(params).toString()
    const key = url.toString()
    if (cache.has(key)) return cache.get(key)
    const job = queue.catch(() => {}).then(async () => {
      check(signal)
      if (cache.has(key)) return cache.get(key)
      await delay(Math.max(0, lastRequestAt + interval - Date.now()), signal)
      check(signal); lastRequestAt = Date.now()
      const response = await fetcher(key, { signal, credentials: 'omit', referrerPolicy: 'strict-origin-when-cross-origin' })
      if (!response.ok) throw Error('GEOCODING_FAILED')
      const data = await response.json()
      if (data.error) throw Error('GEOCODING_FAILED')
      if (cache.size >= 30) cache.delete(cache.keys().next().value)
      cache.set(key, data)
      return data
    })
    queue = job
    return job
  }
  return {
    async reverse(latitude, longitude, { signal } = {}) {
      if (!validLocation(latitude, longitude)) throw Error('INVALID_LOCATION')
      const data = await request(reverseEndpoint, { format: 'jsonv2', lat: Number(latitude).toFixed(7), lon: Number(longitude).toFixed(7), addressdetails: '1', 'accept-language': 'ar' }, signal)
      if (!data.address) throw Error('GEOCODING_FAILED')
      return normalizeGeocodingResult(data)
    },
    async search(query, { signal, city = '', center, countryCode = 'SA' } = {}) {
      const text = query.trim()
      if (text.length < 3) return []
      if (searchFormat === 'photon') {
        const country = /^[A-Z]{2}$/.test(String(countryCode).toUpperCase()) ? String(countryCode).toUpperCase() : 'SA'
        const bounds = searchBounds(center)
        const params = { q: city && !text.includes(city) ? `${text}, ${city}` : text, limit: '10', countrycode: country }
        if (validLocation(center?.latitude, center?.longitude)) {
          params.lat = Number(center.latitude); params.lon = Number(center.longitude)
          params.bbox = bounds.join(',')
        }
        const data = await request(searchEndpoint, params, signal)
        if (!Array.isArray(data.features)) throw Error('GEOCODING_FAILED')
        return data.features.filter(item => {
          const [longitude,latitude] = item?.geometry?.coordinates || []
          if (!validLocation(latitude,longitude)) return false
          const details=item.properties||{}, resultCountry=String(details.countrycode||'').toUpperCase()
          if (resultCountry && resultCountry!==country) return false
          if (bounds && (longitude<bounds[0] || latitude<bounds[1] || longitude>bounds[2] || latitude>bounds[3])) return false
          const resultCity=details.city||details.town||details.village
          if (city && resultCity && normalizedCity(resultCity)!==normalizedCity(city)) return false
          return true
        }).slice(0,5).map((item, index) => {
          const details = item.properties || {}
          return {
            id: `${details.osm_type || ''}:${details.osm_id || index}:${index}`,
            latitude: Number(item.geometry.coordinates[1]), longitude: Number(item.geometry.coordinates[0]),
            city: details.city || details.town || details.village || '',
            district: details.district || details.locality || '', street: details.street || '',
            formattedAddress: [...new Set([details.name, details.street, details.district, details.city, details.state, details.country].filter(Boolean))].join('، '),
          }
        })
      }
      const data = await request(searchEndpoint, { format: 'jsonv2', q: city && !text.includes(city) ? `${text}, ${city}` : text, addressdetails: '1', limit: '5', 'accept-language': 'ar' }, signal)
      if (!Array.isArray(data)) throw Error('GEOCODING_FAILED')
      return data.filter(item => validLocation(item.lat, item.lon)).map(item => ({
        ...normalizeGeocodingResult(item), id: String(item.place_id), latitude: Number(item.lat), longitude: Number(item.lon),
      }))
    },
  }
}

// Photon supports search-as-you-type. Public Nominatim stays reverse/submit-only.
export const createDeliveryLocationProvider = options => createNominatimProvider({
  searchUrl: env.VITE_LOCATION_AUTOCOMPLETE_URL || '/map-geocoding/autocomplete', searchFormat: 'photon', ...options,
})
let provider = createDeliveryLocationProvider()
export function setLocationProvider(value) {
  if (typeof value?.reverse !== 'function' || typeof value?.search !== 'function') throw Error('INVALID_LOCATION_PROVIDER')
  provider = value
}
export const reverseGeocode = (...args) => provider.reverse(...args)
export const searchLocations = (...args) => provider.search(...args)
