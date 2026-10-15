import { ref, watch, onBeforeUnmount } from 'vue'
import { reverseGeocode } from '../services/geocodingService.js'
import { validLocation } from '../utils/location.js'

export function useAddressLocation(form, disabled, { onChange = () => {} } = {}) {
  const showMap = ref(validLocation(form.latitude, form.longitude)), opened = ref(Boolean(form.district)), locating = ref(false), reverseGeocoding = ref(false)
  const userLocation = ref(null), moving = ref(false)
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
    moving.value = false; opened.value = true; showMap.value = true; onChange()
    message.value = 'راجع الموقع تحت الدبوس. لتغييره اضغط «تعديل الموقع على الخريطة».'
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
    }, 800)
  }
  function startMoving() {
    if (disabled()) return
    moving.value = true; cancelLocation(); cancelReverse()
    formattedAddress.value = ''; postalCode.value = ''; geocodingMessage.value = ''; accuracy.value = null
    // The point is not committed while the map moves; no quote may use it.
    form.latitude = ''; form.longitude = ''
    onChange()
  }
  function openMap() {
    if (disabled()) return
    cancelLocation(); opened.value = true; showMap.value = true
    if (!validLocation(form.latitude, form.longitude)) onChange()
    message.value = 'الخريطة مقفلة. اضغط «تعديل الموقع على الخريطة» إذا أردت تغيير مكان الدبوس.'
  }
  function manualAddress() {
    if (disabled()) return
    cancelLocation(); cancelReverse(); moving.value = false; showMap.value = false; opened.value = true
    form.latitude = ''; form.longitude = ''; formattedAddress.value = ''; postalCode.value = ''; accuracy.value = null
    message.value = ''; geocodingMessage.value = ''; onChange()
  }
  function locate() {
    if (disabled() || locating.value) return
    cancelLocation()
    const current = revision
    const failed = error => {
      if (current !== revision) return
      cancelLocation(); opened.value = true
      const reason = error?.code === 1 ? 'تم رفض إذن الموقع. فعّل الإذن من إعدادات المتصفح أو اختر عنوانك على الخريطة.'
        : error?.code === 3 ? 'انتهت مهلة تحديد الموقع. حاول مجددًا قرب نافذة أو اختر عنوانك على الخريطة.'
          : 'تعذر الحصول على موقعك. تحقق من تشغيل خدمات الموقع أو اختر عنوانك على الخريطة.'
      message.value = reason
    }
    if (!window.isSecureContext) { failed(); message.value = 'تحديد الموقع يحتاج اتصال HTTPS. يمكنك اختيار العنوان على الخريطة الآن.'; return }
    if (!navigator.geolocation) { failed(); return }
    locating.value = true; message.value = 'جارٍ تحديد موقعك…'
    locationTimer = setTimeout(() => failed({ code: 3 }), 20000)
    try {
      navigator.geolocation.getCurrentPosition(position => {
        if (current !== revision || disabled()) return
        if (!validLocation(position.coords.latitude, position.coords.longitude)) { failed(); return }
        userLocation.value = { latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy: position.coords.accuracy }
        selectLocation(position.coords)
      }, failed, { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 })
    } catch { failed() }
  }
  watch(disabled, value => { if (value) { cancelLocation(); cancelReverse() } })
  onBeforeUnmount(() => { cancelLocation(); cancelReverse() })
  function recenter() { if (userLocation.value) selectLocation(userLocation.value); else locate() }
  return { showMap, opened, locating, reverseGeocoding, moving, userLocation, message, geocodingMessage, formattedAddress, postalCode, accuracy, selectLocation, startMoving, openMap, manualAddress, locate, recenter }
}
