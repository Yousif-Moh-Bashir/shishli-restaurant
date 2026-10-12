<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { validLocation } from '../../utils/location.js'
import 'leaflet/dist/leaflet.css'
const props = defineProps({ latitude: [Number, String], longitude: [Number, String], center: Object, disabled: Boolean, radiusKm: [Number, String] })
const emit = defineEmits(['selected'])
const container = ref(null), loading = ref(true), error = ref(''), tileError = ref(false)
let map, marker, leaflet, resizeObserver, disposed = false
let coverageCircle
function updateCoverage() {
  if (!map) return
  const radius = Number(props.radiusKm)
  if (!validLocation(props.latitude, props.longitude) || !Number.isFinite(radius) || radius <= 0) {
    coverageCircle?.remove(); coverageCircle = null; return
  }
  const point = [Number(props.latitude), Number(props.longitude)]
  if (!coverageCircle) coverageCircle = leaflet.circle(point, { radius: radius * 1000, color: '#d71920', fillOpacity: 0.1, interactive: false }).addTo(map)
  else coverageCircle.setLatLng(point).setRadius(radius * 1000)
  map.fitBounds(coverageCircle.getBounds(), { padding: [24, 24], maxZoom: 16, animate: false })
}
function selected(latitude, longitude) {
  if (!props.disabled && validLocation(latitude, longitude)) emit('selected', { latitude: Number(latitude), longitude: Number(longitude) })
}
function updateMarker() {
  if (!map || !validLocation(props.latitude, props.longitude)) { if (marker) { marker.remove(); marker = null } return }
  const point = [Number(props.latitude), Number(props.longitude)]
  if (!marker) {
    const icon = leaflet.divIcon({ className: 'shishli-map-pin', html: '<span aria-hidden="true">●</span>', iconSize: [32, 40], iconAnchor: [16, 40] })
    marker = leaflet.marker(point, { draggable: !props.disabled, icon, title: 'موقع التوصيل: اسحب لتغييره', keyboard: true }).addTo(map)
    marker.on('dragend', () => { const point = marker.getLatLng(); selected(point.lat, point.lng) })
  } else marker.setLatLng(point)
  map.panTo(point, { animate: false })
}
function chooseCenter() { if (map) { const point = map.getCenter(); selected(point.lat, point.lng) } }
function syncDisabled() {
  if (!map) return
  for (const key of ['dragging', 'touchZoom', 'doubleClickZoom', 'boxZoom', 'keyboard']) props.disabled ? map[key].disable() : map[key].enable()
  if (marker) props.disabled ? marker.dragging.disable() : marker.dragging.enable()
}
onMounted(async () => {
  try {
    leaflet = await import('leaflet')
    if (disposed) return
    const center = validLocation(props.latitude, props.longitude) ? [Number(props.latitude), Number(props.longitude)]
      : validLocation(props.center?.latitude, props.center?.longitude) ? [Number(props.center.latitude), Number(props.center.longitude)] : [28.3835, 36.5662]
    map = leaflet.map(container.value, { scrollWheelZoom: false, zoomAnimation: false, markerZoomAnimation: false }).setView(center, 15)
    leaflet.tileLayer(import.meta.env.VITE_MAP_TILE_URL || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, attribution: import.meta.env.VITE_MAP_ATTRIBUTION || '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).on('tileerror', () => { tileError.value = true }).on('tileload', () => { tileError.value = false }).addTo(map)
    map.on('click', event => selected(event.latlng.lat, event.latlng.lng))
    updateMarker(); updateCoverage(); syncDisabled()
    resizeObserver = new ResizeObserver(() => map?.invalidateSize())
    resizeObserver.observe(container.value)
  } catch { error.value = 'تعذر عرض الخريطة. يمكنك إعادة فتحها أو كتابة العنوان يدويًا.' }
  finally { loading.value = false }
})
watch(() => [props.latitude, props.longitude], updateMarker)
watch(() => [props.latitude, props.longitude, props.radiusKm], updateCoverage)
watch(() => props.disabled, syncDisabled)
onBeforeUnmount(() => { disposed = true; resizeObserver?.disconnect(); map?.remove(); map = null })
</script>
<template>
  <div class="location-map-picker">
    <p v-if="loading" role="status">جارٍ تحميل الخريطة…</p>
    <p v-if="error || tileError" role="status">{{ error || 'تعذر تحميل صور الخريطة. تحقق من الاتصال أو استخدم العنوان اليدوي.' }}</p>
    <div ref="container" class="location-map" role="region" aria-label="خريطة اختيار عنوان التوصيل" dir="ltr"/>
    <button v-if="!loading && !error" type="button" class="button" :disabled="disabled" @click="chooseCenter">اختيار منتصف الخريطة</button>
    <p class="catalog-note">اضغط على موقعك أو اسحب الدبوس لتصحيح مكان التوصيل. يمكنك تحريك الخريطة ثم اختيار منتصفها.</p>
  </div>
</template>
<style scoped>
.location-map-picker{min-width:0;max-width:100%;margin-block:16px}.location-map{height:300px;width:100%;max-width:100%;border:1px solid var(--border);border-radius:12px;isolation:isolate;z-index:0}.location-map-picker>.button{margin-top:12px;min-height:44px;white-space:normal}.location-map-picker p{font-size:13px;line-height:1.8}.location-map :deep(.shishli-map-pin){background:transparent;border:0}.location-map :deep(.shishli-map-pin span){display:flex;justify-content:center;align-items:center;width:30px;height:36px;background:var(--primary,#d71920);color:white;border:2px solid white;border-radius:50% 50% 50% 0;transform:rotate(-45deg);box-shadow:0 2px 6px #0005;font-size:20px}.location-map :deep(.leaflet-control-attribution){font-size:10px;max-width:100%;white-space:normal}.location-map :deep(.leaflet-control-zoom a){color:#222;background:white;text-decoration:none}@media(max-width:430px){.location-map{height:250px}}
</style>
