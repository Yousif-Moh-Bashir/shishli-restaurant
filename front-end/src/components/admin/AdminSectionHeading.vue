<script setup>
import AdminIcon from './AdminIcon.vue'
defineProps({ icon: { type: String, default: 'settings' } })
</script>
<template><div class="panel-head admin-section-heading"><h2><span class="stat-icon"><AdminIcon :name="icon"/></span><slot/></h2><div v-if="$slots.actions" class="catalog-actions"><slot name="actions"/></div></div></template>
