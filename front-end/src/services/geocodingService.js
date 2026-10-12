import { validLocation } from '../utils/location.js'

// The provider's URL is public configuration; authentication secrets never belong here.
const endpoint = import.meta.env?.VITE_GEOCODING_REVERSE_URL || 'https://nominatim.openstreetmap.org/reverse'
let nextRequestAt = 0
const cache = new Map()
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
function wait(ms, signal) {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) { reject(new DOMException('Aborted', 'AbortError')); return }
    const abort = () => { clearTimeout(timer); reject(new DOMException('Aborted', 'AbortError')) }
    const timer = setTimeout(() => { signal?.removeEventListener('abort', abort); resolve() }, ms)
    signal?.addEventListener('abort', abort, { once: true })
  })
}
export async function reverseGeocode(latitude, longitude, { signal } = {}) {
  if (!validLocation(latitude, longitude)) throw Error('INVALID_LOCATION')
  if (endpoint === 'disabled') throw Error('GEOCODING_DISABLED')
  const key = Number(latitude).toFixed(7) + ',' + Number(longitude).toFixed(7)
  if (cache.has(key)) return cache.get(key)
  // Rate limit across mounted pickers, with an ephemeral cache only.
  const delay = Math.max(0, nextRequestAt - Date.now())
  nextRequestAt = Date.now() + delay + 1100
  await wait(delay, signal)
  const url = new URL(endpoint, window.location.origin)
  url.search = new URLSearchParams({ format: 'jsonv2', lat: Number(latitude), lon: Number(longitude), addressdetails: '1', 'accept-language': 'ar' }).toString()
  const response = await fetch(url, { signal, credentials: 'omit', referrerPolicy: 'strict-origin-when-cross-origin' })
  if (!response.ok) throw Error('GEOCODING_FAILED')
  const data = await response.json()
  if (data.error || !data.address) throw Error('GEOCODING_FAILED')
  const result = normalizeGeocodingResult(data)
  if (cache.size >= 20) cache.delete(cache.keys().next().value)
  cache.set(key, result)
  return result
}
