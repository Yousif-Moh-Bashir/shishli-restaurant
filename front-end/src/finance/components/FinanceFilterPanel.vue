<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
defineProps({ title: { type: String, default: 'تصفية النتائج' }, summary: String })
const media = window.matchMedia('(min-width: 1025px)')
const desktop = ref(media.matches), expanded = ref(false)
function resize() { desktop.value = media.matches; if (!media.matches) expanded.value = false }
function collapse() { if (!desktop.value) expanded.value = false }
onMounted(() => media.addEventListener ? media.addEventListener('change', resize) : media.addListener(resize))
onBeforeUnmount(() => media.removeEventListener ? media.removeEventListener('change', resize) : media.removeListener(resize))
defineExpose({ collapse })
</script>
<template>
  <details class="finance-filter-panel" :open="desktop || expanded" @toggle="!desktop && (expanded = $event.target.open)">
    <summary><span><FinanceIcon name="settings" />{{ title }}</span><span class="muted finance-filter-summary">{{ summary }}</span><FinanceIcon class="finance-filter-chevron" name="chevron-down" /></summary>
    <slot />
  </details>
</template>
