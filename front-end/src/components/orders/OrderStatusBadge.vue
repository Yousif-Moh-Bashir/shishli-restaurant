<script setup>
import { getOrderStatusLabel } from '../../constants/orderStatus'
defineProps({status:String})
</script>
<template><span class="badge order-status-badge" :class="{'good':status==='completed'||status==='ready','off':status==='cancelled'}">{{ getOrderStatusLabel(status) }}</span></template>
