<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { validLocation } from '../../utils/location.js'
import { mapProvider } from '../../services/locationProvider.js'
import 'leaflet/dist/leaflet.css'
const props = defineProps({ latitude: [Number, String], longitude: [Number, String], center: Object, disabled: Boolean, fixedCenter: Boolean, radiusKm: [Number, String], label: { type: String, default: 'عنوان التوصيل' } })
const locked = defineModel('locked', { type: Boolean, default: false })
const emit = defineEmits(['selected', 'moving'])
const container = ref(null), loading = ref(true), error = ref(''), tileError = ref(false), moving = ref(false)
let map, marker, leaflet, resizeObserver, disposed = false, synchronizing = false, coverageCircle
function pointSelected(latitude, longitude) {
  if (!props.disabled && !locked.value && validLocation(latitude, longitude)) emit('selected', { latitude: Number(latitude), longitude: Number(longitude) })
}
function updateCoverage() {
  if (!map || props.fixedCenter) return
  const radius = Number(props.radiusKm)
  if (!validLocation(props.latitude, props.longitude) || !Number.isFinite(radius) || radius <= 0) { coverageCircle?.remove(); coverageCircle = null; return }
  const point = [Number(props.latitude), Number(props.longitude)]
  if (!coverageCircle) coverageCircle = leaflet.circle(point, { radius: radius * 1000, color: '#d71920', fillOpacity: 0.1, interactive: false }).addTo(map)
  else coverageCircle.setLatLng(point).setRadius(radius * 1000)
  map.fitBounds(coverageCircle.getBounds(), { padding: [24, 24], maxZoom: 16, animate: false })
}
function updateMarker() {
  if (!map || !validLocation(props.latitude, props.longitude)) { if (marker) { marker.remove(); marker = null } return }
  const point = [Number(props.latitude), Number(props.longitude)]
  if (props.fixedCenter) {
    const current = map.getCenter()
    if (Math.abs(current.lat - point[0]) < 0.0000001 && Math.abs(current.lng - point[1]) < 0.0000001) return
    synchronizing = true
    map.panTo(point, { animate: false })
    synchronizing = false
    return
  }
  if (!marker) {
    const icon = leaflet.divIcon({ className: 'shishli-map-pin', html: '<span aria-hidden="true">●</span>', iconSize: [32, 40], iconAnchor: [16, 40] })
    marker = leaflet.marker(point, { draggable: !props.disabled, icon, title: props.label + ': اسحب لتغييره', keyboard: true }).addTo(map)
    marker.on('dragend', () => { const point = marker.getLatLng(); pointSelected(point.lat, point.lng) })
  } else marker.setLatLng(point)
  map.panTo(point, { animate: false })
}
function chooseCenter() { if (map) { const point = map.getCenter(); pointSelected(point.lat, point.lng) } }
function syncDisabled() {
  if (!map) return
  for (const key of ['dragging', 'touchZoom', 'doubleClickZoom', 'boxZoom', 'keyboard', 'scrollWheelZoom']) props.disabled || locked.value || key === 'scrollWheelZoom' ? map[key].disable() : map[key].enable()
  if (marker) props.disabled ? marker.dragging.disable() : marker.dragging.enable()
}
onMounted(async () => {
  try {
    leaflet = await import('leaflet')
    if (disposed) return
    const center = validLocation(props.latitude, props.longitude) ? [Number(props.latitude), Number(props.longitude)]
      : validLocation(props.center?.latitude, props.center?.longitude) ? [Number(props.center.latitude), Number(props.center.longitude)] : [28.3835, 36.5662]
    map = leaflet.map(container.value, { scrollWheelZoom: false, zoomAnimation: false, markerZoomAnimation: false }).setView(center, 15)
    leaflet.tileLayer(mapProvider.tileUrl, { maxZoom: 19, attribution: mapProvider.attribution })
      .on('tileerror', () => { tileError.value = true })
      .on('tileload', () => { tileError.value = false }).addTo(map)
    if (props.fixedCenter) {
      map.on('movestart', () => { if (synchronizing || props.disabled || locked.value) return; moving.value = true; emit('moving') })
      map.on('moveend', () => { if (synchronizing || props.disabled || locked.value || !moving.value) return; moving.value = false; chooseCenter() })
      map.on('click', event => { if (!props.disabled && !locked.value) map.panTo(event.latlng, { animate: false }) })
    } else map.on('click', event => pointSelected(event.latlng.lat, event.latlng.lng))
    updateMarker(); updateCoverage(); syncDisabled()
    resizeObserver = new ResizeObserver(() => map?.invalidateSize({ pan: false }))
    resizeObserver.observe(container.value)
  } catch { error.value = 'تعذر عرض الخريطة. أعد فتحها أو استخدم الإدخال اليدوي إذا كان متاحًا.' }
  finally { loading.value = false }
})
watch(() => [props.latitude, props.longitude], updateMarker)
watch(() => [props.latitude, props.longitude, props.radiusKm], updateCoverage)
watch(() => props.disabled, syncDisabled)
watch(locked, syncDisabled)
onBeforeUnmount(() => { disposed = true; resizeObserver?.disconnect(); map?.remove(); map = null })
</script>
<template>
  <div class="location-map-picker" :class="{'fixed-center-picker':fixedCenter}">
    <p v-if="loading" role="status">جارٍ تحميل الخريطة…</p>
    <p v-if="error || tileError" class="map-error" role="status">{{ error || 'تعذر تحميل بعض صور الخريطة. تحقق من اتصال الإنترنت؛ لا تؤكد موقعًا غير واضح.' }}</p>
    <div class="map-frame" :class="{'is-moving':moving, 'is-locked':locked}">
      <div ref="container" class="location-map" role="region" :aria-label="'خريطة اختيار ' + label" dir="ltr"/>
      <span v-if="fixedCenter && !loading && !error" class="fixed-map-pin" aria-hidden="true"><span>●</span></span>
      <span v-if="fixedCenter && !loading" class="map-instruction">{{ locked ? 'الخريطة مقفلة لتجنب تغيير الموقع بالخطأ' : 'حرّك الخريطة لوضع عنوانك تحت الدبوس، ثم أكّد الموقع' }}</span>
    </div>
    <button v-if="fixedCenter && locked && !loading && !error" type="button" class="button" :disabled="disabled" @click="locked=false">تعديل الموقع على الخريطة</button>
    <button v-if="!fixedCenter && !loading && !error" type="button" class="button" :disabled="disabled" @click="chooseCenter">اختيار منتصف الخريطة</button>
    <p v-if="!fixedCenter" class="catalog-note">اضغط على الموقع المطلوب أو اسحب الدبوس لتعديل {{ label }}. يمكنك تحريك الخريطة ثم اختيار منتصفها.</p>
  </div>
</template>
<style scoped>
.is-locked .location-map{touch-action:pan-y}.is-locked .location-map :deep(.leaflet-control-zoom){display:none}.is-locked .location-map :deep(.leaflet-container),.is-locked .location-map{cursor:default}
.location-map-picker{min-width:0;max-width:100%;margin-block:16px}.map-frame{position:relative}.location-map{height:300px;width:100%;max-width:100%;border:1px solid var(--border);border-radius:12px;isolation:isolate;z-index:0}.location-map-picker>.button{margin-top:12px;min-height:44px;white-space:normal}.location-map-picker p{font-size:13px;line-height:1.8}.location-map :deep(.shishli-map-pin){background:transparent;border:0}.location-map :deep(.shishli-map-pin span),.fixed-map-pin>span{display:flex;justify-content:center;align-items:center;width:30px;height:36px;background:var(--primary,#d71920);color:white;border:2px solid white;border-radius:50% 50% 50% 0;transform:rotate(-45deg);box-shadow:0 2px 6px #0005;font-size:20px}.fixed-map-pin{position:absolute;left:50%;top:50%;transform:translate(-50%,-100%);z-index:1;pointer-events:none;transition:margin-top .15s}.is-moving .fixed-map-pin{margin-top:-7px}.map-instruction{position:absolute;top:12px;left:50%;transform:translateX(-50%);z-index:1;pointer-events:none;background:#fffef2ed;border-radius:18px;padding:7px 12px;font-size:11px;width:max-content;max-width:calc(100% - 100px);text-align:center;box-shadow:0 2px 8px #0002}.location-map :deep(.leaflet-control-attribution){font-size:10px;max-width:100%;white-space:normal}.location-map :deep(.leaflet-control-zoom a){color:#222;background:white;text-decoration:none}.fixed-center-picker .location-map{height:360px}.map-error{padding:8px;background:#fff3e4;border-radius:8px}@media(max-width:430px){.location-map{height:250px}.fixed-center-picker .location-map{height:min(42dvh,340px);min-height:220px}.map-instruction{font-size:10px;padding:6px 8px}}
</style>
