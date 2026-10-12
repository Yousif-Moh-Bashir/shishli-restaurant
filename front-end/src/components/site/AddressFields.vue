<script setup>
import { addressFields } from '../../constants/addressFields'
import PhoneInput from '../common/PhoneInput.vue'
import CheckoutAddress from './CheckoutAddress.vue'
const props = defineProps({ form: Object, errors: { type: Object, default: () => ({}) }, disabled: Boolean, saved: { type: Boolean, default: true }, prefix: { type: String, default: '' }, center: Object })
const errorFor = key => props.errors[props.prefix + key] || props.errors[key]
const identityFields = addressFields.filter(field => ['recipient_name', 'phone', 'label'].includes(field.key))
</script>
<template>
  <div class="address-editor-fields">
    <div class="form-grid address-fields">
      <label v-for="field in identityFields.filter(field => saved || field.key !== 'label')" :key="field.key" class="field"><span>{{ field.label }}{{ field.required ? ' *' : '' }}</span><PhoneInput v-if="field.key==='phone'" v-model="form[field.key]" :name="field.key" :required="field.required" :disabled="disabled" :aria-invalid="!!errorFor(field.key)"/><input v-else v-model="form[field.key]" :name="field.key" :type="field.type" :placeholder="field.key === 'label' ? 'مثال: المنزل أو العمل' : undefined" :required="field.required" :maxlength="field.max" :disabled="disabled" :aria-invalid="!!errorFor(field.key)" :autocomplete="field.key === 'phone' ? 'tel' : field.key === 'recipient_name' ? 'name' : 'off'"><small v-if="field.key === 'label'">اسم يساعدك على تمييز العنوان، مثل: المنزل أو العمل.</small><small v-if="errorFor(field.key)" class="field-error">{{ errorFor(field.key).join(' ') }}</small></label>
    </div>
    <CheckoutAddress :form="form" :errors="errors" :disabled="disabled" :center="center" :checkout="false"/>
    <label v-if="saved" class="check"><input v-model="form.is_default" name="is_default" type="checkbox" :disabled="disabled">العنوان الافتراضي</label>
  </div>
</template>
