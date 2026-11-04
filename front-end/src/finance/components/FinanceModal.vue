<script>
let lockCount = 0
let restoreScroll
function lockPage() {
  if (lockCount++ > 0) return
  const root = document.documentElement, body = document.body
  const x = window.scrollX, y = window.scrollY
  const overflow = root.style.overflow
  const properties = ['position', 'top', 'left', 'right', 'width', 'overflow']
  const previous = Object.fromEntries(properties.map(key => [key, body.style[key]]))
  root.style.overflow = 'hidden'
  Object.assign(body.style, { position: 'fixed', top: `-${y}px`, left: `-${x}px`, right: '0', width: '100%', overflow: 'hidden' })
  restoreScroll = () => {
    root.style.overflow = overflow
    Object.assign(body.style, previous)
    window.scrollTo({ left: x, top: y, behavior: 'instant' })
  }
}
function unlockPage() { if (--lockCount === 0) { restoreScroll?.(); restoreScroll = undefined } }
</script>
<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import AdminIcon from '../../components/admin/AdminIcon.vue'
defineProps({ title: String, busy: Boolean })
const emit = defineEmits(['close'])
const dialog = ref(null)
const viewportStyle = ref({})
const viewport = window.visualViewport
function updateViewport() {
  viewportStyle.value = {
    '--finance-visible-height': `${viewport?.height || window.innerHeight}px`,
    '--finance-visible-top': `${viewport?.offsetTop || 0}px`,
  }
}
let previous
onMounted(() => {
  previous = document.activeElement; lockPage(); updateViewport(); dialog.value.showModal()
  viewport?.addEventListener('resize', updateViewport)
  viewport?.addEventListener('scroll', updateViewport)
  window.addEventListener('resize', updateViewport)
})
onBeforeUnmount(() => {
  viewport?.removeEventListener('resize', updateViewport)
  viewport?.removeEventListener('scroll', updateViewport)
  window.removeEventListener('resize', updateViewport)
  unlockPage(); previous?.focus({ preventScroll: true })
})
</script>
<template><dialog ref="dialog" class="finance-modal" :style="viewportStyle" aria-modal="true" aria-labelledby="finance-modal-title" @cancel.prevent="!busy && emit('close')"><header><h2 id="finance-modal-title">{{ title }}</h2><button class="finance-modal-close" type="button" :disabled="busy" aria-label="إغلاق" @click="emit('close')"><AdminIcon name="x" /></button></header><slot /></dialog></template>
