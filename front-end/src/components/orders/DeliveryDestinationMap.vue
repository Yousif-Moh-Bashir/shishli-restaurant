<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { validLocation } from '../../utils/location'
import 'leaflet/dist/leaflet.css'
const props = defineProps({ location: Object, branch: Object })
const container = ref(null), error = ref('')
let map, leaflet, markers = [], resize, disposed = false
function renderPoints() {
  if (!map) return
  markers.forEach(marker => marker.remove()); markers = []
  if (!validLocation(props.location?.latitude, props.location?.longitude)) return
  const point = [Number(props.location.latitude), Number(props.location.longitude)]
  const destination = leaflet.circleMarker(point, { radius: 10, color: '#fff', weight: 3, fillColor: '#d71920', fillOpacity: 1 }).addTo(map).bindTooltip('موقع العميل')
  markers.push(destination)
  const points = [point]
  if (validLocation(props.branch?.latitude, props.branch?.longitude)) {
    const branch = [Number(props.branch.latitude), Number(props.branch.longitude)]
    markers.push(leaflet.circleMarker(branch, { radius: 8, color: '#fff', weight: 2, fillColor: '#35261f', fillOpacity: 1 }).addTo(map).bindTooltip('موقع الفرع'))
    points.push(branch)
  }
  map.fitBounds(points, { padding: [28, 28], maxZoom: 16, animate: false })
}
onMounted(async () => {
  try {
    leaflet = await import('leaflet')
    if (disposed) return
    map = leaflet.map(container.value, { scrollWheelZoom: false, zoomAnimation: false, markerZoomAnimation: false })
    leaflet.tileLayer(import.meta.env.VITE_MAP_TILE_URL || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: import.meta.env.VITE_MAP_ATTRIBUTION || '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>' }).on('tileerror', () => { error.value = 'تعذر تحميل الخريطة. يمكنك فتح الملاحة مباشرة.' }).on('tileload', () => { error.value = '' }).addTo(map)
    renderPoints(); resize = new ResizeObserver(() => map?.invalidateSize()); resize.observe(container.value)
  } catch { error.value = 'تعذر عرض الخريطة. يمكنك فتح الملاحة مباشرة.' }
})
watch(() => [props.location?.latitude, props.location?.longitude, props.branch?.latitude, props.branch?.longitude], renderPoints)
onBeforeUnmount(() => { disposed = true; resize?.disconnect(); map?.remove(); map = null })
</script>
<template><div><p v-if="error" role="status" class="driver-muted">{{ error }}</p><div ref="container" class="delivery-destination-map" dir="ltr" role="region" aria-label="موقع العميل والفرع على الخريطة"/></div></template>
<style scoped>.delivery-destination-map{height:280px;border-radius:12px;border:1px solid #e8ded4;margin-block:14px;isolation:isolate;z-index:0}.delivery-destination-map :deep(.leaflet-control-attribution){font-size:10px;white-space:normal;max-width:100%}</style>
