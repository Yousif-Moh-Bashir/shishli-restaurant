export function validLocation(latitude, longitude) {
  if (latitude === '' || longitude === '' || latitude == null || longitude == null) return false
  return Number.isFinite(Number(latitude)) && Number.isFinite(Number(longitude))
    && Math.abs(Number(latitude)) <= 90 && Math.abs(Number(longitude)) <= 180
}
