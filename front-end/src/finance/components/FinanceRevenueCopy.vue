<script setup>
import { ref, watch } from 'vue'
import FinanceModal from './FinanceModal.vue'
import { revenueSummary } from '../revenueSummary.js'

const props = defineProps({ record: { type: Object, required: true }, includeBranch: Boolean })
const busy = ref(false), copied = ref(false), manual = ref(''), error = ref(''), textarea = ref(null)
watch(() => props.record, () => { copied.value = false })
async function copy() {
  if (busy.value) return
  copied.value = false; error.value = ''
  let text
  try { text = revenueSummary(props.record, { includeBranch: props.includeBranch }) }
  catch { error.value = 'تعذّر تجهيز الملخص. حدّث سجل الإيرادات وحاول مجددًا.'; return }
  busy.value = true
  try {
    if (!navigator.clipboard?.writeText) throw new Error('CLIPBOARD_UNAVAILABLE')
    await navigator.clipboard.writeText(text)
    copied.value = true; manual.value = ''
  } catch { manual.value = text }
  finally { busy.value = false }
}
function selectText() { textarea.value?.focus(); textarea.value?.select() }
</script>
<template>
  <button type="button" class="finance-revenue-copy" :disabled="busy" aria-live="polite" @click="copy">
    <FinanceIcon v-if="copied" name="badge-check" />
    <svg v-else class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/></svg>
    {{ busy ? 'جارٍ النسخ…' : copied ? 'تم النسخ' : 'نسخ ملخص اليوم' }}
  </button>
  <small v-if="error" role="alert">{{ error }}</small>
  <FinanceModal v-if="manual" title="نسخ ملخص الإيرادات" :busy="busy" @close="manual = ''">
    <div class="modal-body"><p class="muted">لم يسمح المتصفح بالنسخ المباشر. حدّد النص ثم اختر «نسخ» من قائمة جهازك.</p><label class="finance-copy-text">الملخص الجاهز للإرسال<textarea ref="textarea" :value="manual" readonly rows="10" dir="rtl" @focus="$event.target.select()" /></label></div>
    <div class="modal-actions"><button type="button" @click="manual = ''"><FinanceIcon name="x" />إغلاق</button><button type="button" class="primary" @click="selectText">تحديد النص</button></div>
  </FinanceModal>
</template>
<style scoped>
.finance-copy-text{margin-top:16px}.finance-copy-text textarea{line-height:1.9;unicode-bidi:plaintext;resize:vertical;font-size:16px}
</style>
