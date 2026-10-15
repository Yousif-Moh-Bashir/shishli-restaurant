<script setup>
import { computed } from 'vue'
import { addressFields } from '../../constants/addressFields'
const props = defineProps({ form: Object, errors: { type: Object, default: () => ({}) }, disabled: Boolean, manual: Boolean, coverage: Object })
const basics = addressFields.filter(field => ['city', 'district', 'street'].includes(field.key))
const extras = addressFields.filter(field => ['building_number', 'floor', 'apartment', 'landmark', 'notes'].includes(field.key))
const errorFor = key => props.errors['address.' + key] || props.errors[key]
const incomplete = computed(() => !props.form.city?.trim() || !props.form.district?.trim() || basics.some(field => errorFor(field.key)))
</script>
<template>
  <div class="location-address-details">
    <details class="address-text-details" :open="manual || incomplete">
      <summary>{{ incomplete ? 'أكمل المدينة والحي' : 'مراجعة وتعديل وصف العنوان' }}</summary>
      <div class="form-grid address-fields">
        <label v-for="field in basics" :key="field.key" class="field"><span>{{ field.label }}{{ field.required ? ' *' : ' (اختياري)' }}</span>
          <select v-if="manual && ['city','district'].includes(field.key)" v-model="form[field.key]" :name="field.key" required :disabled="disabled" :aria-invalid="!!errorFor(field.key)">
            <option value="">{{ field.key === 'city' ? 'اختر المدينة' : 'اختر الحي' }}</option>
            <option v-for="value in field.key === 'city' ? (coverage?.city ? [coverage.city] : []) : (form.city === coverage?.city ? coverage?.districts || [] : [])" :key="value" :value="value">{{ value }}</option>
          </select>
          <input v-else v-model="form[field.key]" :name="field.key" :maxlength="field.max" :required="field.required" :disabled="disabled" :aria-invalid="!!errorFor(field.key)" :autocomplete="field.key === 'city' ? 'address-level2' : field.key === 'street' ? 'address-line1' : 'off'">
          <small v-if="errorFor(field.key)" class="field-error">{{ errorFor(field.key).join(' ') }}</small>
        </label>
      </div>
    </details>
    <details :open="extras.some(field => errorFor(field.key))"><summary>المبنى وتفاصيل الوصول (اختياري)</summary><div class="form-grid address-fields">
      <label v-for="field in extras" :key="field.key" class="field"><span>{{ field.label }}</span>
        <textarea v-if="field.type === 'textarea'" v-model="form[field.key]" :name="field.key" :maxlength="field.max" :disabled="disabled" rows="3" placeholder="مثال: الدور الثاني، الاتصال عند الوصول"/>
        <input v-else v-model="form[field.key]" :name="field.key" :maxlength="field.max" :disabled="disabled">
        <small v-if="errorFor(field.key)" class="field-error">{{ errorFor(field.key).join(' ') }}</small>
      </label>
    </div></details>
    <p v-for="key in ['latitude','longitude']" v-show="errorFor(key)" :key="key" class="field-error">{{ errorFor(key)?.join(' ') }}</p>
  </div>
</template>
<style scoped>
.location-address-details{min-width:0}summary{cursor:pointer;min-height:44px;display:flex;align-items:center;color:var(--primary-dark);font-weight:700;font-size:13px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:12px 0}.field{display:flex;flex-direction:column;gap:7px;min-width:0;font-size:13px}.field input,.field select,.field textarea{width:100%;max-width:100%;box-sizing:border-box;min-height:46px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:white;color:var(--primary-dark);font:inherit}.field-error{color:#a42b2b}details+details{border-top:1px solid var(--border)}@media(max-width:700px){.form-grid{grid-template-columns:minmax(0,1fr)}}
</style>
