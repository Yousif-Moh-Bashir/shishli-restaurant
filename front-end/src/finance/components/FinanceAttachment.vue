<script setup>
import { ref, watch, onBeforeUnmount } from 'vue'

const props = defineProps({ modelValue: { default: null }, busy: Boolean, hasStoredAttachment: Boolean })
const emit = defineEmits(['update:modelValue'])
const fileInput = ref(null), cameraInput = ref(null), preview = ref(''), error = ref('')
function releasePreview() { if (preview.value) URL.revokeObjectURL(preview.value); preview.value = '' }
watch(() => props.modelValue, file => {
  releasePreview()
  if (file && /\.(jpe?g|png)$/i.test(file.name)) preview.value = URL.createObjectURL(file)
}, { immediate: true })
onBeforeUnmount(releasePreview)
function choose(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file || props.busy) return
  error.value = ''
  if (!/\.(pdf|jpe?g|png)$/i.test(file.name) || (file.type && !['application/pdf', 'image/jpeg', 'image/png'].includes(file.type))) {
    error.value = 'اختر صورة JPG أو PNG، أو مستند PDF.'
    return
  }
  if (file.size > 10 * 1024 * 1024) { error.value = 'حجم المرفق يجب ألا يتجاوز 10 ميغابايت.'; return }
  emit('update:modelValue', file)
}
function clear() { if (!props.busy) { error.value = ''; emit('update:modelValue', null) } }
</script>
<template>
  <section class="finance-attachment" aria-label="مرفق فاتورة المشتريات">
    <div class="finance-attachment-heading"><strong>صورة الفاتورة أو المستند</strong><span class="muted">اختياري</span></div>
    <p class="muted">اختر صورة من جهازك أو التقط صورة واضحة للفاتورة.</p>
    <input ref="fileInput" class="finance-attachment-input" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" :disabled="busy" aria-label="اختيار ملف الفاتورة" @change="choose">
    <input ref="cameraInput" class="finance-attachment-input finance-camera-input" type="file" accept="image/jpeg,image/png" capture="environment" :disabled="busy" aria-label="التقاط صورة الفاتورة" @change="choose">
    <div class="finance-attachment-actions">
      <button type="button" :disabled="busy" @click="fileInput.click()"><FinanceIcon name="upload" />{{ modelValue ? 'تغيير الملف' : 'اختيار صورة أو PDF' }}</button>
      <button type="button" :disabled="busy" @click="cameraInput.click()"><FinanceIcon name="panels-top-left" />التقاط صورة</button>
    </div>
    <div v-if="modelValue" class="finance-attachment-selected">
      <img v-if="preview" :src="preview" alt="معاينة صورة الفاتورة المختارة">
      <div v-else class="finance-attachment-pdf"><strong>PDF</strong><span>مستند الفاتورة المختار</span></div>
      <div class="finance-attachment-file"><div><strong>{{ modelValue.name }}</strong><small>{{ Math.ceil(modelValue.size / 1024).toLocaleString('ar-SA') }} كيلوبايت · لم يُحفظ بعد</small></div><button type="button" :disabled="busy" @click="clear" aria-label="إزالة الملف المختار"><FinanceIcon name="x" />إزالة الاختيار</button></div>
    </div>
    <p v-if="error" class="notice danger" role="alert">{{ error }}<span v-if="modelValue"> بقي الملف المختار سابقًا دون تغيير.</span></p>
    <small>JPG أو PNG أو PDF · حتى 10 ميغابايت. خيار الكاميرا يعتمد على دعم جهازك ومتصفحك.</small>
    <small v-if="hasStoredAttachment">يوجد مرفق محفوظ. الملف الجديد يستبدله عند الحفظ؛ إزالة الاختيار لا تحذف المرفق المحفوظ.</small>
  </section>
</template>
