<script setup>
defineProps({order:{type:Object,required:true}})
</script>
<template><section class="panel kitchen-snapshot"><h2>تفاصيل التجهيز</h2><article v-for="item in order.items" :key="item.id"><h3><strong>{{ item.quantity }} × </strong>{{ item.product.name }}</h3><p v-for="(option,index) in item.options" :key="index">{{ option.group.name }}: {{ option.value.name }}</p></article><section v-if="order.customer_notes||order.notes"><h3>ملاحظات العميل</h3><p class="kitchen-notes">{{ order.customer_notes||order.notes }}</p></section></section></template>
<style scoped>.kitchen-snapshot{min-width:0}.kitchen-snapshot article{padding-block:12px;border-bottom:1px solid var(--line)}.kitchen-snapshot h3{font-size:1.25rem;overflow-wrap:anywhere}.kitchen-snapshot h3 strong{font-size:1.5rem;color:var(--red)}.kitchen-notes{white-space:pre-wrap;font-weight:700;overflow-wrap:anywhere}</style>
