<script setup>
import { computed } from 'vue'
const props = defineProps({
  variant: { type: String, default: 'list' },
  rows: { type: Number, default: 4 },
  label: { type: String, default: 'جارٍ تحميل البيانات…' },
})
const count = computed(() => Math.max(1, Math.min(12, props.rows)))
</script>
<template>
  <div class="app-skeleton" :class="'skeleton-'+variant" role="status" aria-live="polite" aria-busy="true">
    <span class="skeleton-status">{{ label }}</span>
    <div v-if="variant==='inline'" class="skeleton-inline-content" aria-hidden="true"><span class="skeleton-shape skeleton-line"/><span class="skeleton-shape skeleton-line skeleton-short"/></div>
    <div v-else-if="variant==='table'" class="skeleton-table-content" aria-hidden="true"><div v-for="row in count+1" :key="row" class="skeleton-table-row"><span v-for="cell in 5" :key="cell" class="skeleton-shape skeleton-line"/></div></div>
    <div v-else-if="variant==='cards'||variant==='stats'" class="skeleton-card-grid" aria-hidden="true"><div v-for="row in count" :key="row" class="skeleton-card"><span class="skeleton-shape" :class="variant==='stats'?'skeleton-icon':'skeleton-photo'"/><span class="skeleton-shape skeleton-line"/><span class="skeleton-shape skeleton-line skeleton-short"/><span v-if="variant==='cards'" class="skeleton-shape skeleton-button"/></div></div>
    <div v-else-if="variant==='detail'||variant==='page'" class="skeleton-detail-content" aria-hidden="true"><div class="skeleton-detail-main"><span class="skeleton-shape skeleton-title"/><span class="skeleton-shape skeleton-photo"/><span v-for="row in count" :key="row" class="skeleton-shape skeleton-line"/></div><div class="skeleton-detail-summary"><span class="skeleton-shape skeleton-title"/><span v-for="row in count+2" :key="row" class="skeleton-shape skeleton-line"/><span class="skeleton-shape skeleton-button"/></div></div>
    <div v-else class="skeleton-list-content" aria-hidden="true"><div v-for="row in count" :key="row" class="skeleton-list-row"><span class="skeleton-shape skeleton-icon"/><div><span class="skeleton-shape skeleton-line"/><span class="skeleton-shape skeleton-line skeleton-short"/></div></div></div>
  </div>
</template>
