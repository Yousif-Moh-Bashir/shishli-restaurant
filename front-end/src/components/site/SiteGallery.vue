<script setup>
import { computed, ref, watch, nextTick, useId, onMounted, onBeforeUnmount } from 'vue'
import SiteIcon from './SiteIcon.vue'
import { safeContentUrl } from '../../utils/contentUrl'

const props = defineProps({ items: { type: Array, default: () => [] }, carousel: Boolean })
const track = ref(null), preview = ref(null), active = ref(0), selected = ref(0)
const atEnd = ref(false)
const galleryRoot=ref(null),paused=ref(false),hovering=ref(false),focusWithin=ref(false)
let autoTimer,observer,inView=false
const titleId = useId()
const photos = computed(() => props.items.filter(item => item.photo || safeContentUrl(item.image_url)))
const current = computed(() => photos.value[selected.value])
const progress = computed(() => photos.value.length ? ((active.value + 1) / photos.value.length) * 100 : 0)
watch(photos, () => { active.value = 0; selected.value = 0; atEnd.value = false; preview.value?.close() })
function updatePosition() {
  const container = track.value
  if (!container) return
  atEnd.value = Math.abs(container.scrollLeft) >= container.scrollWidth - container.clientWidth - 2
  const edge = container.getBoundingClientRect().right
  let distance = Infinity
  Array.from(container.children).forEach((item, index) => {
    const delta = Math.abs(item.getBoundingClientRect().right - edge)
    if (delta < distance) { distance = delta; active.value = index }
  })
}
function move(direction) {
  const index = Math.max(0, Math.min(photos.value.length - 1, active.value + direction))
  const container = track.value, card = container?.children[index]
  if (!card) return
  const offset = card.getBoundingClientRect().right - container.getBoundingClientRect().right
  container.scrollBy({ left: offset, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' })
}
async function open(index) { selected.value = index; await nextTick(); preview.value?.showModal() }
function change(direction) { selected.value = (selected.value + direction + photos.value.length) % photos.value.length }
function keys(event) {
  if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
    event.preventDefault()
    const direction = event.key === 'ArrowLeft' ? 1 : -1
    if (preview.value?.open) change(direction); else move(direction)
  }
}
function autoplay(){
  if(!props.carousel||photos.value.length<2||paused.value||hovering.value||focusWithin.value||preview.value?.open||document.visibilityState!=='visible'||!inView)return
  const container=track.value
  if(!container||container.scrollWidth<=container.clientWidth+2)return
  if(atEnd.value)container.scrollTo({left:0,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'})
  else move(1)
}
onMounted(()=>{
  paused.value=matchMedia('(prefers-reduced-motion: reduce)').matches
  observer=new IntersectionObserver(entries=>{inView=entries[0]?.isIntersecting||false},{threshold:0.2})
  if(galleryRoot.value)observer.observe(galleryRoot.value)
  autoTimer=setInterval(autoplay,2000)
})
onBeforeUnmount(()=>{clearInterval(autoTimer);observer?.disconnect()})
</script>

<template>
  <div ref="galleryRoot" class="site-gallery" :class="{ 'site-gallery-carousel': carousel }" @pointerenter="event=>hovering=event.pointerType==='mouse'" @pointerleave="hovering=false" @focusin="focusWithin=true" @focusout="event=>focusWithin=galleryRoot?.contains(event.relatedTarget)||false">
    <div ref="track" :class="carousel ? 'gallery-banner-photos' : 'site-gallery-grid'" :tabindex="carousel ? 0 : undefined" :role="carousel ? 'region' : undefined" aria-label="صور شيشلي" @scroll.passive="updatePosition" @keydown="keys">
      <figure v-for="(item,index) in photos" :key="item.id || item.photo || index" class="gallery-banner-item">
        <button class="gallery-photo-button" type="button" :aria-label="'تكبير الصورة: ' + (item.title || 'من أطباق شيشلي')" @click="open(index)">
          <div v-if="item.photo" class="product-photo" :class="'photo-' + item.photo" role="img" :aria-label="item.alt_text || 'من أطباق شيشلي'"/>
          <img v-else class="cms-gallery-photo" :src="safeContentUrl(item.image_url)" :alt="item.alt_text || item.title || ''" loading="lazy" decoding="async">
          <span class="gallery-zoom"><SiteIcon name="search"/> عرض الصورة</span>
        </button>
        <figcaption><div><h3>{{ item.title || 'من أطباق شيشلي' }}</h3><p v-if="item.description">{{ item.description }}</p></div><span class="gallery-photo-number">{{ String(index + 1).padStart(2,'0') }}</span></figcaption>
      </figure>
    </div>
    <div v-if="carousel && photos.length" class="gallery-bottom">
      <div class="gallery-progress" aria-hidden="true"><span class="gallery-progress-fill" :style="{ width: progress + '%' }"/></div>
      <div class="gallery-controls"><button class="gallery-autoplay-button" type="button" :aria-pressed="paused" @click="paused=!paused">{{ paused?'تشغيل تلقائي':'إيقاف مؤقت' }}</button><button class="icon-button" type="button" aria-label="الصور السابقة" :disabled="active===0" @click="move(-1)">→</button><span class="gallery-count">{{ active + 1 }} / {{ photos.length }}</span><button class="icon-button" type="button" aria-label="الصور التالية" :disabled="atEnd || photos.length<2" @click="move(1)">←</button></div>
    </div>
    <dialog ref="preview" class="gallery-lightbox" :aria-labelledby="titleId" @keydown="keys" @click="event => { if(event.target === preview) preview.close() }">
      <template v-if="current">
        <div class="gallery-lightbox-head"><h2 :id="titleId">{{ current.title || 'من أطباق شيشلي' }}</h2><button class="icon-button" type="button" aria-label="إغلاق الصورة" @click="preview.close()"><SiteIcon name="x"/></button></div>
        <div class="gallery-lightbox-image"><div v-if="current.photo" class="product-photo" :class="'photo-' + current.photo" role="img" :aria-label="current.alt_text || 'من أطباق شيشلي'"/><img v-else :src="safeContentUrl(current.image_url)" :alt="current.alt_text || current.title || ''"></div>
        <div class="gallery-lightbox-foot"><button class="icon-button" type="button" aria-label="الصورة السابقة" :disabled="photos.length<2" @click="change(-1)">→</button><div><p v-if="current.description">{{ current.description }}</p><span aria-live="polite">{{ selected + 1 }} / {{ photos.length }}</span></div><button class="icon-button" type="button" aria-label="الصورة التالية" :disabled="photos.length<2" @click="change(1)">←</button></div>
      </template>
    </dialog>
  </div>
</template>
