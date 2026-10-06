<script setup>
import { paths } from '../../data/icons'
defineProps({ name: String })
</script>
<template><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" v-html="paths[name] || paths['shopping-bag']"></svg></template>
