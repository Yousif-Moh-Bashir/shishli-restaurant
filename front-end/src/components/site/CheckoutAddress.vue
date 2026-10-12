<script setup>
import { watch, defineAsyncComponent, onBeforeUnmount } from 'vue'
import { addressFields } from '../../constants/addressFields'
import { useAddressLocation } from '../../composables/useAddressLocation.js'
import { validLocation } from '../../utils/location.js'
const props = defineProps({ form: Object, errors: { type: Object, default: () => ({}) }, disabled: Boolean, center: Object, checkout: { type: Boolean, default: true } })
const emit = defineEmits(['confirm', 'busy', 'changed'])
const MapLocationPicker = defineAsyncComponent(() => import('./MapLocationPicker.vue'))
const { showMap, opened, locating, reverseGeocoding, message, geocodingMessage, formattedAddress, postalCode, accuracy, selectLocation, openMap, manualAddress, locate } = useAddressLocation(props.form, () => props.disabled, { onChange: () => emit('changed') })
const errorFor = key => props.errors['address.' + key] || props.errors[key]
const basics = addressFields.filter(field => ['city', 'district', 'street', 'building_number', 'apartment'].includes(field.key))
const extras = addressFields.filter(field => ['floor', 'landmark', 'notes'].includes(field.key))
function enterManualAddress() {
  manualAddress()
  if (!props.form.city) props.form.city = props.center?.city || ''
}
defineExpose({ openMap })
watch([locating, reverseGeocoding], ([locating, reversing]) => emit('busy', locating || reversing))
onBeforeUnmount(() => emit('busy', false))
watch(() => props.errors, errors => { if (Object.keys(errors).length) opened.value = true }, { deep: true })
</script>
<template>
  <div class="checkout-location">
    <div class="location-actions">
      <button type="button" class="button button-primary" :disabled="disabled || locating" :aria-busy="locating" @click="locate">{{ locating ? 'جارٍ تحديد موقعي…' : 'تحديد موقعي الحالي' }}</button>
      <button type="button" class="button" :disabled="disabled" @click="openMap">اختيار الموقع على الخريطة</button>
      <button type="button" class="text-button" :disabled="disabled" @click="enterManualAddress">كتابة العنوان يدويًا</button>
    </div>
    <p v-if="message" class="location-status" role="status" aria-live="polite">{{ message }}</p>
    <p v-if="!opened" class="catalog-note">حدد مكان التوصيل باستخدام الموقع أو الخريطة، أو اكتب العنوان يدويًا.</p>
    <template v-if="showMap">
      <MapLocationPicker :latitude="form.latitude" :longitude="form.longitude" :center="center" :disabled="disabled" @selected="selectLocation"/>
      <p v-if="accuracy != null && accuracy > 100" class="catalog-note">دقة الموقع تقريبية. حرّك الدبوس للتأكد من مكان التوصيل.</p>
      <p v-if="reverseGeocoding" role="status">جارٍ جلب وصف العنوان…</p>
      <p v-if="formattedAddress" class="selected-address"><strong>الموقع المحدد:</strong> {{ formattedAddress }}</p>
      <p v-if="postalCode" class="catalog-note">الرمز البريدي للموقع: {{ postalCode }}</p>
      <p v-if="geocodingMessage" role="status">{{ geocodingMessage }}</p>
      <p class="catalog-note">وصف الموقع: © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>. تُرسل نقطة الاختيار لخدمة وصف الموقع عند استخدامها.</p>
    </template>
    <div v-if="opened" class="form-grid address-fields">
      <label v-for="field in basics" :key="field.key" class="field"><span>{{ field.label }}{{ field.required ? ' *' : ' (اختياري)' }}</span><input v-model="form[field.key]" :name="field.key" :maxlength="field.max" :required="field.required" :disabled="disabled || locating" :aria-invalid="!!errorFor(field.key)" :autocomplete="field.key === 'city' ? 'address-level2' : field.key === 'street' ? 'address-line1' : 'off'"><small v-if="errorFor(field.key)" class="field-error">{{ errorFor(field.key).join(' ') }}</small></label>
      <details class="field-wide" :open="extras.some(field => errorFor(field.key))"><summary>تفاصيل الوصول (اختياري)</summary><div class="form-grid">
        <label v-for="field in extras" :key="field.key" class="field"><span>{{ field.label }}</span><textarea v-if="field.type === 'textarea'" v-model="form[field.key]" :name="field.key" :maxlength="field.max" :disabled="disabled || locating" rows="3" placeholder="مثال: الدور الثاني، الاتصال عند الوصول"/><input v-else v-model="form[field.key]" :name="field.key" :maxlength="field.max" :disabled="disabled || locating"><small v-if="errorFor(field.key)" class="field-error">{{ errorFor(field.key).join(' ') }}</small></label>
      </div></details>
      <p v-if="checkout" class="field-wide catalog-note">نستخدم الاسم والجوال من بيانات التواصل أعلاه.</p>
      <p v-for="key in ['latitude', 'longitude']" v-show="errorFor(key)" :key="key" class="field-error field-wide">{{ errorFor(key)?.join(' ') }}</p>
    </div>
    <button v-if="checkout && opened" type="button" class="button button-primary location-confirm" :disabled="disabled || locating || reverseGeocoding || showMap && !validLocation(form.latitude, form.longitude)" @click="emit('confirm')">تأكيد هذا الموقع والتحقق من التوصيل</button>
  </div>
</template>
<style scoped>
.checkout-location{min-width:0;max-width:100%}.location-actions{display:flex;flex-wrap:wrap;gap:10px}.location-actions>.button{flex:1 1 180px;min-height:46px;justify-content:center;white-space:normal}.location-actions>.text-button{min-height:44px}.checkout-location>p{font-size:13px;line-height:1.8;overflow-wrap:anywhere}.location-status{padding:10px 12px;border-radius:8px;background:#f7f2eb;color:var(--primary-dark)}.selected-address{padding:12px;border:1px solid var(--border);border-radius:8px}.location-confirm{width:100%;min-height:46px;justify-content:center;white-space:normal;margin-top:16px}.checkout-location>.address-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-block:16px}.field-wide{grid-column:1/-1}.checkout-location .field{min-width:0}.checkout-location input,.checkout-location textarea{max-width:100%;width:100%;box-sizing:border-box}summary{cursor:pointer;min-height:40px;color:var(--primary-dark)}details .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:12px}@media(max-width:700px){details .form-grid,.checkout-location>.address-fields{grid-template-columns:1fr}.location-actions>.button{flex-basis:100%}}
</style>
