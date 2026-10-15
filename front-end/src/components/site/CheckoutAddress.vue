<script setup>
import { computed, ref, watch, nextTick, defineAsyncComponent, onBeforeUnmount } from 'vue'
import { useAddressLocation } from '../../composables/useAddressLocation.js'
import { validLocation } from '../../utils/location.js'
import { searchLocations } from '../../services/locationProvider.js'
import { formatCurrency } from '../../utils/formatters'
import LocationAddressDetails from './LocationAddressDetails.vue'
import { usePublicSettingsStore } from '../../stores/publicSettings'
const props = defineProps({ form: Object, errors: { type: Object, default: () => ({}) }, disabled: Boolean, center: Object, coverage: Object, requireCoordinates: Boolean, quote: Object, quoteError: Object, quoting: Boolean, checkout: { type: Boolean, default: true } })
const emit = defineEmits(['confirm', 'busy', 'changed'])
const MapLocationPicker = defineAsyncComponent(() => import('./MapLocationPicker.vue'))
const { showMap, opened, locating, reverseGeocoding, moving, userLocation, message, geocodingMessage, formattedAddress, accuracy, selectLocation, startMoving, openMap: beginMap, manualAddress, locate: beginLocate, recenter } = useAddressLocation(props.form, () => props.disabled, { onChange: () => emit('changed') })
const sheet = ref(null), sheetOpen = ref(false), query = ref(''), searching = ref(false), results = ref([]), searchMessage = ref('')
let searchRevision = 0, searchController, searchTimer, autocompleteTimer
const composing = ref(false)
const mapLocked = ref(true)
const publicSettings = usePublicSettingsStore()
const countryCode = computed(() => publicSettings.data?.restaurant?.country || 'SA')
const coveredDistricts = computed(() => props.coverage?.city ? props.coverage.districts || [] : [])
const manualAvailable = computed(() => !props.checkout || !props.requireCoordinates && coveredDistricts.value.length > 0)
const addressSummary = computed(() => [props.form.city,props.form.district,props.form.street].filter(Boolean).join('، ') || formattedAddress.value)
const validText = computed(() => Boolean(props.form.city?.trim() && props.form.district?.trim()))
const canConfirm = computed(() => !props.disabled && !locating.value && !reverseGeocoding.value && !moving.value && !props.quoting && validText.value && validLocation(props.form.latitude, props.form.longitude))
function cancelSearch() { searchRevision++; searchController?.abort(); clearTimeout(searchTimer); clearTimeout(autocompleteTimer); searching.value = false; results.value = [] }
function openMap() { if (props.disabled) return; beginMap(); sheetOpen.value = true }
function locate() { if (props.disabled) return; sheetOpen.value = true; beginMap(); beginLocate() }
function closeSheet() { mapLocked.value = true; sheetOpen.value = false; moving.value = false; cancelSearch() }
function enterManualAddress() {
  if (!manualAvailable.value) { openMap(); return }
  closeSheet(); manualAddress()
  if (props.checkout) { props.form.city = props.coverage.city; if (!coveredDistricts.value.includes(props.form.district)) props.form.district = '' }
  else if (!props.form.city) props.form.city = props.center?.city || ''
}
function confirmLocation() { if (!canConfirm.value) return; closeSheet(); if (props.checkout) emit('confirm') }
function mapMoving() { cancelSearch(); startMoving() }
async function search() {
  if (props.disabled || !sheetOpen.value || composing.value || query.value.trim().length < 3) { searchMessage.value = 'اكتب ثلاثة أحرف على الأقل لعرض النتائج.'; return }
  cancelSearch(); searchMessage.value = ''; searching.value = true
  const version = searchRevision, controller = new AbortController(); searchController = controller
  searchTimer = setTimeout(() => controller.abort(), 12000)
  try {
    const value = await searchLocations(query.value, { signal: controller.signal, city: props.center?.city || '', center: props.center, countryCode: countryCode.value })
    if (version !== searchRevision) return
    results.value = value
    if (!value.length) searchMessage.value = 'لم نجد نتيجة. جرّب اسم شارع أو معلم قريب أو حرّك الخريطة.'
  } catch { if (version === searchRevision) searchMessage.value = 'تعذر البحث الآن. يمكنك تحريك الخريطة أو استخدام موقعك الحالي.' }
  finally { if (version === searchRevision) { searching.value = false; clearTimeout(searchTimer) } }
}
function chooseResult(result) { mapLocked.value = true; cancelSearch(); query.value = ''; selectLocation(result) }
function returnToUserLocation() { mapLocked.value = true; recenter() }
watch(locating, value => { if (value) mapLocked.value = true })
function scheduleSearch() {
  cancelSearch(); searchMessage.value = ''
  if (!sheetOpen.value || props.disabled || composing.value || query.value.trim().length < 3) return
  autocompleteTimer = setTimeout(search, 600)
}
watch(query, scheduleSearch, { flush: 'sync' })
watch(() => [props.center?.city,props.center?.latitude,props.center?.longitude,countryCode.value], scheduleSearch, { flush: 'sync' })
watch(() => props.disabled, disabled => { if (disabled) cancelSearch() })
watch(sheetOpen, async value => { await nextTick(); if (value && !sheet.value?.open) sheet.value?.showModal(); else if (!value && sheet.value?.open) sheet.value.close() })
watch(() => props.coverage, () => {
  if (!props.checkout || showMap.value || !opened.value) return
  props.form.city = props.coverage?.city || ''
  if (!coveredDistricts.value.includes(props.form.district)) props.form.district = ''
  if (!manualAvailable.value) openMap()
})
watch(() => props.requireCoordinates, required => { if (props.checkout && required && opened.value && !showMap.value) openMap() })
watch([locating, reverseGeocoding, moving], values => emit('busy', values.some(Boolean)))
watch(() => props.errors, errors => { if (Object.keys(errors).some(key => key === 'address' || key.startsWith('address.') || ['city','district','street','latitude','longitude'].includes(key))) { opened.value = true; if (showMap.value) sheetOpen.value = true } }, { deep: true })
defineExpose({ openMap })
onBeforeUnmount(() => { cancelSearch(); sheet.value?.close(); emit('busy', false) })
</script>
<template>
  <div class="checkout-location">
    <div class="location-actions">
      <button type="button" class="button button-primary" :disabled="disabled || locating" :aria-busy="locating" @click="locate">{{ locating ? 'جارٍ تحديد موقعي…' : 'استخدام موقعي الحالي' }}</button>
      <button type="button" class="button" :disabled="disabled" @click="openMap">اختيار الموقع على الخريطة</button>
      <button v-if="manualAvailable" type="button" class="text-button" :disabled="disabled" @click="enterManualAddress">كتابة العنوان يدويًا</button>
    </div>
    <p v-if="!sheetOpen && message" class="location-status" role="status">{{ message }}</p>
    <p v-if="!opened" class="catalog-note">{{ manualAvailable ? 'استخدم موقعك، ابحث على الخريطة، أو اختر المدينة والحي يدويًا.' : 'حدد مكان التوصيل على الخريطة للتحقق من التغطية والرسوم.' }}</p>
    <p v-if="opened && showMap && !sheetOpen && addressSummary" class="selected-address"><strong>عنوان التوصيل:</strong> {{ addressSummary }} <button type="button" class="text-button" :disabled="disabled" @click="openMap">تعديل الموقع والتفاصيل</button></p>
    <template v-if="opened && !showMap">
      <p v-if="checkout" class="catalog-note">اختر من أحياء توصيل الفرع. إذا لم تجد حيّك، استخدم الخريطة للتحقق من موقعك.</p>
      <LocationAddressDetails :form="form" :errors="errors" :disabled="disabled" :manual="checkout" :coverage="coverage"/>
      <button v-if="checkout" type="button" class="button location-confirm" :disabled="disabled || quoting || !manualAvailable || form.city !== coverage?.city || !coveredDistricts.includes(form.district)" @click="emit('confirm')">استخدام هذا العنوان</button>
    </template>
    <Teleport to=".site-layout">
      <dialog ref="sheet" class="location-sheet" aria-labelledby="location-sheet-title" @cancel.prevent="closeSheet">
        <template v-if="sheetOpen">
          <header class="location-sheet-heading"><div><h2 id="location-sheet-title">أين نوصّل طلبك؟</h2><p>ضع عنوانك تحت الدبوس، ثم راجع التفاصيل.</p></div><button type="button" class="text-button sheet-close" aria-label="إغلاق اختيار الموقع" :disabled="disabled" @click="closeSheet">×</button></header>
          <div class="location-sheet-body">
            <div class="location-search" role="search"><label for="location-search-input">ابحث عن حي أو شارع أو معلم{{ center?.city ? ' في ' + center.city : '' }}</label><div><input id="location-search-input" v-model="query" type="search" maxlength="150" placeholder="مثال: شارع الملك خالد" autocomplete="off" :disabled="disabled" @keydown.enter.prevent="search" @compositionstart="composing=true;cancelSearch()" @compositionend="composing=false;scheduleSearch()"><button type="button" class="button" :disabled="disabled || searching || query.trim().length<3" @click="search">{{ searching ? 'جارٍ البحث…' : 'بحث' }}</button></div></div>
            <p v-if="searchMessage" role="status" class="catalog-note">{{ searchMessage }}</p>
            <ul v-if="results.length" class="location-search-results" aria-label="نتائج البحث"><li v-for="result in results" :key="result.id"><button type="button" :disabled="disabled" @click="chooseResult(result)">{{ result.formattedAddress }}</button></li></ul>
            <div class="sheet-gps-actions"><button type="button" class="button" :disabled="disabled || locating" :aria-busy="locating" @click="beginLocate">{{ locating ? 'جارٍ تحديد موقعي…' : 'استخدام موقعي الحالي' }}</button><button v-if="userLocation" type="button" class="text-button" :disabled="disabled || locating" @click="returnToUserLocation">العودة إلى موقعي</button></div>
            <p v-if="message" class="location-status" role="status" aria-live="polite">{{ message }}</p>
            <MapLocationPicker v-model:locked="mapLocked" :latitude="form.latitude" :longitude="form.longitude" :center="center" :disabled="disabled || locating" fixed-center @moving="mapMoving" @selected="selectLocation"/>
            <p v-if="accuracy != null && accuracy > 100" class="gps-accuracy" role="status">دقة موقعك تقريبًا {{ Math.round(accuracy) }} متر. استخدم «تعديل الموقع على الخريطة» للتأكد من مكان الباب أو المبنى.</p>
            <p v-if="reverseGeocoding" role="status" class="catalog-note">جارٍ جلب وصف العنوان…</p>
            <p v-else-if="addressSummary" class="selected-address"><strong>الموقع المحدد:</strong> {{ addressSummary }}</p>
            <p v-if="geocodingMessage" class="catalog-note" role="status">{{ geocodingMessage }}</p>
            <p class="catalog-note">وصف العنوان تقريبي؛ تأكد أن الدبوس عند مكان التوصيل الصحيح.</p>
            <LocationAddressDetails :form="form" :errors="errors" :disabled="disabled || locating"/>
            <p class="location-attribution">وصف الموقع: © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>. يُرسل الموقع المختار لخدمة وصف العنوان، والبحث تلقائيًا بعد استقرار الكتابة.</p>
          </div>
          <footer class="location-sheet-footer">            <div v-if="checkout" class="sheet-delivery-status" role="status" aria-live="polite">
              <p v-if="moving">حرّك الخريطة، وسنتحقق من التوصيل بعد استقرار الموقع.</p>
              <p v-else-if="quoting">جارٍ التحقق من التغطية ورسوم التوصيل…</p>
              <p v-else-if="quote?.available">التوصيل متاح · {{ Number(quote.delivery_fee)===0 ? 'توصيل مجاني' : 'رسوم التوصيل ' + formatCurrency(quote.delivery_fee) }}</p>
              <p v-else-if="quote?.issue">{{ quote.issue.message }}</p>
              <p v-else-if="quoteError">{{ quoteError.message }} <button type="button" class="text-button" @click="emit('confirm')">إعادة التحقق</button></p>
              <p v-else>أكمل المدينة والحي للتحقق من التوصيل تلقائيًا.</p>
            </div>
<button type="button" class="button button-primary location-confirm" :disabled="!canConfirm" @click="confirmLocation">تأكيد هذا الموقع</button></footer>
        </template>
      </dialog>
    </Teleport>
  </div>
</template>
<style scoped>
.checkout-location{min-width:0;max-width:100%}.location-actions{display:flex;flex-wrap:wrap;gap:10px}.location-actions>.button{flex:1 1 180px;min-height:46px;justify-content:center;white-space:normal}.location-actions>.text-button{min-height:44px}.checkout-location>p,.location-sheet p{font-size:13px;line-height:1.8;overflow-wrap:anywhere}.location-status{padding:10px 12px;border-radius:8px;background:#f7f2eb;color:var(--primary-dark);margin-block:10px}.selected-address{padding:12px;border:1px solid var(--border);border-radius:8px;background:#fffdfa}.selected-address .text-button{display:block;min-height:40px}.location-confirm{width:100%;min-height:48px;justify-content:center;white-space:normal}.location-sheet{padding:0;border:1px solid var(--border);border-radius:18px;width:min(640px,calc(100% - 32px));max-width:100%;max-height:90dvh;background:#fffdfa;color:var(--primary-dark);box-sizing:border-box;overflow:hidden;margin:auto;font-family:inherit}.location-sheet[open]{display:flex;flex-direction:column}.location-sheet::backdrop{background:#20150e99}.location-sheet-heading{display:flex;gap:12px;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);flex-shrink:0}.location-sheet-heading h2{font-size:19px;margin:0}.location-sheet-heading p{margin:4px 0 0;color:var(--muted)}.sheet-close{font-size:26px;min-width:44px;min-height:44px;align-self:flex-start}.location-sheet-body{padding:16px 20px;overflow:auto;overscroll-behavior:contain;min-height:0;min-width:0}.location-sheet-footer{padding:12px 20px max(12px,env(safe-area-inset-bottom));border-top:1px solid var(--border);background:#fffdfa;flex-shrink:0}.location-search label{font-size:13px;font-weight:700;display:block;margin-bottom:8px}.location-search>div{display:flex;gap:8px;min-width:0}.location-search input{flex:1;min-width:0;width:100%;font:inherit;font-size:16px;min-height:46px;padding:10px;border:1px solid var(--border);border-radius:8px;background:#fff}.location-search .button{min-width:65px;min-height:46px}.location-search-results{list-style:none;margin:8px 0;padding:0;border:1px solid var(--border);border-radius:10px;background:#fff;max-height:210px;overflow:auto}.location-search-results li+li{border-top:1px solid var(--border)}.location-search-results button{font:inherit;font-size:13px;text-align:start;padding:12px;width:100%;min-height:48px;border:0;background:transparent;color:var(--primary-dark);overflow-wrap:anywhere;cursor:pointer}.location-search-results button:focus-visible{outline:2px solid var(--primary);outline-offset:-2px}.sheet-gps-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px}.sheet-gps-actions .button,.sheet-gps-actions .text-button{min-height:44px}.gps-accuracy{padding:10px;background:#fff0d8;border-radius:8px}.sheet-delivery-status{padding:8px 12px;background:#f4f7f0;border-radius:8px;margin-bottom:10px}.sheet-delivery-status p{margin:0}.location-attribution{font-size:10px!important;color:var(--muted);margin:12px 0 0}.location-sheet :deep(.field input),.location-sheet :deep(.field select),.location-sheet :deep(.field textarea){font-size:16px}@media(max-width:700px){.location-sheet{width:100%;max-width:100%;max-height:94dvh;margin:auto 0 0;border-radius:18px 18px 0 0;border-bottom:0}.location-sheet-heading{padding:14px 16px}.location-sheet-body{padding:12px 16px}.location-sheet-footer{padding-inline:16px}.location-actions>.button{flex-basis:100%}}@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto}}
</style>
