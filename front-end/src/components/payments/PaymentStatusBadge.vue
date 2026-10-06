<script setup>
import {getPaymentStatusLabel,paymentBadgeClass} from '../../utils/paymentStatus'
defineProps({status:String})
</script>
<template><span class="badge payment-status-badge" :class="paymentBadgeClass(status)">{{ getPaymentStatusLabel(status) }}</span></template>
