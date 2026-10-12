import { ref, watch, onBeforeUnmount } from 'vue'
import { reverseGeocode } from '../services/geocodingService.js'
import { validLocation } from '../utils/location.js'

export function useAddressLocation(form, disabled, { onChange = () => {} } = {}) {
  const showMap = ref(false), opened = ref(Boolean(form.district)), locating = ref(false), reverseGeocoding = ref(false)
  const message = ref(''), geocodingMessage = ref(''), formattedAddress = ref(''), postalCode = ref(''), accuracy = ref(null)
  let revision = 0, geoRevision = 0, locationTimer, reverseTimer, controller
  function cancelReverse() {
    geoRevision++; clearTimeout(reverseTimer); controller?.abort(); reverseGeocoding.value = false
  }
  function cancelLocation() { revision++; clearTimeout(locationTimer); locating.value = false }
  function selectLocation(location) {
    if (disabled()) return
    cancelLocation(); cancelReverse()
    if (!validLocation(location.latitude, location.longitude)) return
    form.latitude = Number(location.latitude); form.longitude = Number(location.longitude)
    form.city = ''; form.district = ''; form.street = ''
    formattedAddress.value = ''; postalCode.value = ''; geocodingMessage.value = ''
    accuracy.value = Number.isFinite(location.accuracy) ? location.accuracy : null
    opened.value = true; showMap.value = true; onChange()
    message.value = 'تم اختيار الموقع. يمكنك تعديل الدبوس ومراجعة العنوان.'
    const current = geoRevision
    // Preserve any text typed by the customer while the provider is responding.
    const original = { city: form.city, district: form.district, street: form.street }
    reverseGeocoding.value = true
    reverseTimer = setTimeout(async () => {
      const requestController = new AbortController()
      controller = requestController
      const timeout = setTimeout(() => requestController.abort(), 10000)
      try {
        const result = await reverseGeocode(form.latitude, form.longitude, { signal: requestController.signal })
        if (current !== geoRevision || disabled()) return
        for (const key of ['city', 'district', 'street']) if (form[key] === original[key]) form[key] = result[key].slice(0, key === 'street' ? 200 : 100)
        formattedAddress.value = result.formattedAddress; postalCode.value = result.postalCode
        geocodingMessage.value = 'راجع المدينة والحي؛ قد تكون بعض تفاصيل العنوان غير متوفرة.'
      } catch {
        if (current === geoRevision) geocodingMessage.value = 'تم حفظ الموقع، لكن تعذر جلب وصف العنوان. أكمل المدينة والحي يدويًا للمتابعة.'
      } finally { clearTimeout(timeout); if (current === geoRevision) reverseGeocoding.value = false }
    }, 500)
  }
  function openMap() {
    if (disabled()) return
    cancelLocation(); opened.value = true; showMap.value = true
    onChange()
    message.value = 'اضغط على مكان التوصيل في الخريطة أو حرّك الدبوس ثم أكد الموقع.'
  }
  function manualAddress() {
    if (disabled()) return
    cancelLocation(); cancelReverse(); showMap.value = false; opened.value = true
    form.latitude = ''; form.longitude = ''; formattedAddress.value = ''; postalCode.value = ''; accuracy.value = null
    message.value = ''; geocodingMessage.value = ''; onChange()
  }
  function locate() {
    if (disabled() || locating.value) return
    cancelLocation()
    const current = revision
    const failed = () => {
      if (current !== revision) return
      cancelLocation(); opened.value = true
      message.value = 'لم نتمكن من الوصول إلى موقعك. يمكنك اختيار موقعك على الخريطة أو كتابة العنوان يدويًا.'
    }
    if (!window.isSecureContext || !navigator.geolocation) { failed(); return }
    locating.value = true; message.value = 'جارٍ تحديد موقعك…'
    locationTimer = setTimeout(failed, 15000)
    try {
      navigator.geolocation.getCurrentPosition(position => {
        if (current !== revision || disabled()) return
        if (!validLocation(position.coords.latitude, position.coords.longitude)) { failed(); return }
        selectLocation(position.coords)
      }, failed, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 })
    } catch { failed() }
  }
  watch(disabled, value => { if (value) { cancelLocation(); cancelReverse() } })
  onBeforeUnmount(() => { cancelLocation(); cancelReverse() })
  return { showMap, opened, locating, reverseGeocoding, message, geocodingMessage, formattedAddress, postalCode, accuracy, selectLocation, openMap, manualAddress, locate }
}
