import { validLocation } from '../utils/location.js'

export const masifLocation = Object.freeze({
  latitude: 28.4378538,
  longitude: 36.5455267,
  mapUrl: 'https://maps.app.goo.gl/CdZ7w1xxKYsLiaqk9',
})

export function branchDefaultLocation(branch) {
  if (validLocation(branch?.location?.latitude, branch?.location?.longitude)) return branch.location
  if (branch?.slug === 'al-masif' || branch?.name === 'فرع المصيف') return masifLocation
  return null
}
