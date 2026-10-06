<script setup>
import AppSkeleton from "../common/AppSkeleton.vue"
import {ref,reactive,watch,inject} from 'vue'
import {addressesApi} from '../../api/addresses.api'
import {useAuthStore} from '../../stores/auth'
import {useApiState,useApiMutation} from '../../composables/useApiState'
import {addressForm,addressPayload} from '../../constants/addressFields'
import AddressFields from './AddressFields.vue'
import ApiPagination from '../admin/catalog/ApiPagination.vue'
const props=defineProps({selectable:Boolean,selectedId:String})
const emit=defineEmits(['selected','changed'])
const auth=useAuthStore(),state=useApiState(),mutation=useApiMutation(),recordLoading=ref(false),page=ref(1),editing=ref(null),showForm=ref(false),form=reactive(addressForm()),deleting=ref(null),confirmation=ref(null)
const {toast}=inject('site')
let revision=0
function load(){if(auth.isAuthenticated)return state.run(signal=>addressesApi.list({page:page.value},signal))}
defineExpose({reload:load})
watch(page,load)
watch(()=>auth.token,()=>{revision++;state.clear();mutation.error.value=null;showForm.value=false;Object.assign(form,addressForm());emit('selected',null);load()},{immediate:true})
async function edit(record){
  mutation.error.value=null
  const version=revision
  if(record){recordLoading.value=true;let result;try{result=await mutation.run(()=>addressesApi.show(record.id))}finally{recordLoading.value=false};if(!result.ok){if(mutation.error.value?.status===404)load();return}if(version!==revision)return;record=result.data}
  editing.value=record;Object.assign(form,addressForm(record||{}));showForm.value=true
}
async function save(){
  const version=revision
  const result=await mutation.run(()=>editing.value?addressesApi.update(editing.value.id,addressPayload(form)):addressesApi.create(addressPayload(form)))
  if(version!==revision)return
  if(result.ok){showForm.value=false;toast('تم حفظ العنوان.');emit('changed');if(props.selectedId===result.data.id||props.selectable)emit('selected',result.data);await load()}
  else if(mutation.error.value?.status===404){emit('selected',null);load()}
}
async function setDefault(record){const version=revision;const result=await mutation.run(()=>addressesApi.setDefault(record.id));if(version!==revision)return;if(result.ok){toast('تم تحديث العنوان الافتراضي.');emit('changed');load()}else if(mutation.error.value?.status===404)load()}
async function remove(){const record=deleting.value,version=revision;const result=await mutation.run(()=>addressesApi.remove(record.id));confirmation.value.close();if(version!==revision)return;if(result.ok){toast('تم حذف العنوان.');if(props.selectedId===record.id)emit('selected',null);emit('changed');if(state.data.value?.data.length===1&&page.value>1)page.value--;else load()}else if(mutation.error.value?.status===404){emit('selected',null);load()}}
</script>
<template><section class="address-book" aria-labelledby="address-book-title"><div class="dialog-heading"><h2 id="address-book-title">{{ selectable?'اختر عنوان التوصيل':'عناويني' }}</h2><button type="button" class="button button-primary" :disabled="mutation.busy.value||!auth.isAuthenticated" @click="edit(null)">إضافة عنوان</button></div><AppSkeleton v-if="state.loading.value" variant="list" label="جارٍ تحميل العناوين…"/><div v-if="state.error.value" role="alert"><p>{{ state.error.value.message }}</p><button type="button" class="text-button" @click="load">إعادة المحاولة</button></div><p v-if="state.data.value&&!state.data.value.data.length" class="catalog-note">ليس لديك عناوين محفوظة. يمكنك إضافة عنوان أو إدخاله يدويًا للتوصيل.</p><article v-for="item in state.data.value?.data||[]" :key="item.id" class="checkout-panel address-card"><h3>{{ item.label||item.recipient_name }} <span v-if="item.is_default" class="badge good">العنوان الافتراضي</span></h3><p>{{ item.recipient_name }} · {{ item.phone }}</p><p>{{ item.city }}، {{ item.district }}، {{ item.street }} {{ item.building_number }}</p><p v-if="item.floor||item.apartment">الطابق {{ item.floor }} · الشقة {{ item.apartment }}</p><p v-if="item.landmark">{{ item.landmark }}</p><p v-if="item.notes">{{ item.notes }}</p><div class="form-actions"><button type="button" v-if="selectable" class="button" :aria-pressed="selectedId===item.id" :disabled="mutation.busy.value" @click="emit('selected',item)">{{ selectedId===item.id?'العنوان المختار':'اختيار للتوصيل' }}</button><button type="button" class="text-button" :disabled="mutation.busy.value" @click="edit(item)">تعديل</button><button type="button" v-if="!item.is_default" class="text-button" :disabled="mutation.busy.value" @click="setDefault(item)">تعيين افتراضي</button><button type="button" class="text-button" :disabled="mutation.busy.value" @click="deleting=item;confirmation.showModal()">حذف</button></div></article><ApiPagination :meta="state.data.value?.meta" :links="state.data.value?.links" :busy="state.loading.value||mutation.busy.value" @page="page=$event"/><AppSkeleton v-if="recordLoading" variant="inline" label="جارٍ تحميل تفاصيل العنوان…"/><form v-if="showForm" class="checkout-panel address-editor" @submit.prevent="save"><h3>{{ editing?'تعديل العنوان':'عنوان جديد' }}</h3><AddressFields :form="form" :errors="mutation.error.value?.errors" :disabled="mutation.busy.value"/><div class="form-actions"><button type="submit" class="button button-primary" :disabled="mutation.busy.value">{{ mutation.busy.value?'جارٍ الحفظ…':'حفظ العنوان' }}</button><button type="button" class="button" :disabled="mutation.busy.value" @click="showForm=false;Object.assign(form,addressForm())">إلغاء</button></div></form><div v-if="mutation.error.value" role="alert"><p>{{ mutation.error.value.message }}</p><p v-for="(messages,key) in mutation.error.value.errors" :key="key">{{ messages.join(' ') }}</p></div><dialog ref="confirmation" class="info-dialog" aria-labelledby="delete-address-title"><h2 id="delete-address-title">حذف العنوان؟</h2><p>سيحذف العنوان من قائمة عناوينك. إذا كان افتراضيًا، يختار الخادم عنوانًا بديلًا إن وجد.</p><button type="button" class="button button-primary" :disabled="mutation.busy.value" @click="remove">حذف</button><button type="button" class="button" :disabled="mutation.busy.value" @click="confirmation.close()">إلغاء</button></dialog></section></template>

