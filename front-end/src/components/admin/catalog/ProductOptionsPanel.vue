<script setup>
import {inject as injectAdminConfirmation} from 'vue'
const {confirmAction}=injectAdminConfirmation('admin')
import AdminIcon from '../AdminIcon.vue'
import AdminSectionHeading from '../AdminSectionHeading.vue'
import { ref, reactive, computed, inject } from 'vue'
import { optionGroupsApi, productOptionsApi } from '../../../api/productOptions.api'
import { catalogFields,initialForm,formPayload } from '../../../constants/catalogFields'
import { useAuthStore } from '../../../stores/auth'
import { useApiMutation } from '../../../composables/useApiState'
import CatalogFields from './CatalogFields.vue'
import ApiFeedback from './ApiFeedback.vue'
import RemotePicker from './RemotePicker.vue'
const props=defineProps({product:Object}),emit=defineEmits(['changed']),auth=useAuthStore(),mutation=useApiMutation()
const {notify}=inject('admin'),selected=ref(''),editing=ref(null),form=reactive(initialForm(catalogFields.assignment)),showForm=ref(false)
const excluded=computed(()=>props.product.option_groups?.map(group=>group.id)||[])
function edit(group=null){editing.value=group;selected.value='';Object.assign(form,initialForm(catalogFields.assignment,{...group?.overrides,sort_order:group?.sort_order??0}));mutation.error.value=null;showForm.value=true}
async function submit(){
  const payload=formPayload(catalogFields.assignment,form)
  const result=await mutation.run(()=>editing.value?productOptionsApi.update(props.product.id,editing.value.id,payload):productOptionsApi.attach(props.product.id,{...payload,option_group_uuid:selected.value}))
  if(result.ok){showForm.value=false;notify('تم حفظ ربط الخيارات.');emit('changed')}
}
async function detach(group){if(!(await confirmAction('فك ربط «'+group.name+'» بهذا المنتج؟')))return;const result=await mutation.run(()=>productOptionsApi.detach(props.product.id,group.id));if(result.ok){notify('تم فك الربط.');emit('changed')}}
</script>
<template><section class="panel catalog-section"><AdminSectionHeading icon="settings">خيارات المنتج</AdminSectionHeading><div class="table-wrap"><table v-admin-table><thead><tr><th>المجموعة</th><th>الإعدادات الفعلية لهذا المنتج</th><th>الإجراءات</th></tr></thead><tbody><tr v-for="group in product.option_groups||[]" :key="group.id"><td><div class="admin-cell-value">{{ group.name }}<br><small>{{ group.is_active?'نشطة':'متوقفة' }}</small></div></td><td><div class="admin-cell-value">{{ group.is_required?'إلزامية':'اختيارية' }} · {{ group.min_select }}–{{ group.max_select??'بلا حد' }}<br>ترتيب {{ group.sort_order }}</div></td><td v-if="auth.can('products.update')"><div class="admin-cell-value"><div class="admin-row-actions"><button class="row-button" :disabled="mutation.busy.value" @click="edit(group)">تعديل الحدود والترتيب</button><button class="row-button danger" :disabled="mutation.busy.value" @click="detach(group)"><AdminIcon name="x"/> فك الربط</button></div></div></td></tr></tbody></table></div><p v-if="!excluded.length" class="empty">لم تُربط خيارات بعد.</p>
<button v-if="auth.can('products.update')&&auth.can('options.view')" class="row-button" :disabled="mutation.busy.value" @click="edit()">ربط مجموعة خيارات</button><p v-else-if="auth.can('products.update')&&!auth.can('options.view')" class="form-note">عرض المجموعات المتاحة للربط يحتاج صلاحية options.view.</p>
<form v-if="showForm" class="catalog-subform" @submit.prevent="submit"><RemotePicker v-if="!editing" v-model="selected" :api="optionGroupsApi" :exclude="excluded" label="اختر مجموعة الخيارات"/><CatalogFields :fields="catalogFields.assignment" :form="form" :errors="mutation.error.value?.errors" :disabled="mutation.busy.value"/><p class="form-note">اترك الحدود فارغة لاستخدام إعداد المجموعة؛ يتحقق الخادم من توافقها مع قيم المجموعة.</p><div class="form-actions"><button class="button" :disabled="mutation.busy.value||(!editing&&!selected)">حفظ الربط</button><button class="row-button" type="button" :disabled="mutation.busy.value" @click="showForm=false">إلغاء</button></div></form><ApiFeedback v-if="mutation.error.value" :error="mutation.error.value" @retry="mutation.error.value=null"/></section></template>

