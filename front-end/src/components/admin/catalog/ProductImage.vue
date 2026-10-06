<script setup>
import { ref, watch, computed } from 'vue'
import AdminIcon from '../AdminIcon.vue'
import {safeContentUrl} from '../../../utils/contentUrl'
const props = defineProps({ image: String, name: String })
const failed = ref(false)
watch(() => props.image, () => { failed.value = false })
const source = computed(() => safeContentUrl(props.image))
</script>
<template><img v-if="source && !failed" class="product-thumb" :src="source" :alt="name" loading="lazy" @error="failed = true"><span v-else class="product-thumb product-placeholder" aria-label="بدون صورة"><AdminIcon name="utensils" /></span></template>
