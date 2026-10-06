<script setup>
import { computed, ref } from 'vue'
const props = defineProps({ number: { type: String, required: true } })
const copied = ref(false)
const shortNumber = computed(() => {
  const value = props.number.replace(/^#+/, '')
  return /[0-9A-HJKMNP-TV-Z]{26}$/i.test(value) ? value.slice(-8).toUpperCase() : value
})
async function copyNumber() {
  try {
    await navigator.clipboard.writeText(props.number)
    copied.value = true
  } catch {
    copied.value = false
  }
}
</script>
<template>
  <div class="order-number-display">
    <span class="order-number-label">رقم الطلب</span>
    <strong class="order-number-short" dir="ltr">{{ shortNumber }}</strong>
    <details v-if="shortNumber !== number" class="order-number-full">
      <summary>الرقم الكامل</summary>
      <div><bdi>{{ number }}</bdi><button type="button" class="row-button" @click="copyNumber">{{ copied ? 'تم النسخ' : 'نسخ الرقم' }}</button></div>
      <small>استخدم الرقم الكامل للبحث المطابق والتتبع.</small>
    </details>
  </div>
</template>
