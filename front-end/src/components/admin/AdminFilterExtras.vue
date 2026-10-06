<script setup>
import { ref, watch } from 'vue'
import AdminIcon from './AdminIcon.vue'
const props = defineProps({ errors: Object })
const open = ref(false)
watch(() => props.errors, errors => { if (errors && Object.keys(errors).length) open.value = true }, { immediate: true })
</script>
<template>
  <details class="admin-filter-extras" :open="open" @toggle="open=$event.target.open">
    <summary><AdminIcon name="settings"/> تصفية وترتيب إضافي <AdminIcon name="chevron-down"/></summary>
    <div class="admin-filter-grid"><slot/></div>
  </details>
</template>
