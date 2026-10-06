<script setup>
import {inject as injectAdminConfirmation} from 'vue'
const {confirmAction}=injectAdminConfirmation('admin')
import AdminIcon from '../AdminIcon.vue'
import AdminSectionHeading from '../AdminSectionHeading.vue'
import { ref, reactive, watch, inject } from 'vue'
import { branchesApi,branchProductsApi } from '../../../api/branches.api'
import { useAuthStore } from '../../../stores/auth'
import { useApiState,useApiMutation } from '../../../composables/useApiState'
import { catalogFields,initialForm,formPayload } from '../../../constants/catalogFields'
import { formatCurrency } from '../../../utils/formatters'
import CatalogFields from './CatalogFields.vue'
import ApiFeedback from './ApiFeedback.vue'
import ApiPagination from './ApiPagination.vue'
const props=defineProps({product:Object}),emit=defineEmits(['changed'])
const state=useApiState(),mutation=useApiMutation(),auth=useAuthStore(),page=ref(1),editing=ref(null),form=reactive(initialForm(catalogFields.branchProduct))
const {notify}=inject('admin')
async function load(){
  await state.run(async signal=>{
    const response=await branchesApi.list({page:page.value,per_page:20},signal)
    const rows=await Promise.all(response.data.map(async branch=>{
      const attached=props.product.branches?.some(item=>item.id===branch.id)
      if(!attached)return {...branch,assignment:null}
      // There is no product_uuid list filter. Search and traverse actual server pages.
      let current=1,assignment=null,result
      do{result=await branchProductsApi.list(branch.id,{search:props.product.name,per_page:100,page:current++},signal);assignment=result.data.find(row=>row.product.id===props.product.id)||null}while(!assignment&&result.links.next)
      return {...branch,assignment}
    }))
    return {...response,data:rows}
  })
}
watch(()=>props.product,()=>{editing.value=null;if(auth.can('branches.view'))load()},{immediate:true})
watch(page,load)
function edit(branch){editing.value=branch;Object.assign(form,initialForm(catalogFields.branchProduct,branch.assignment||{}));mutation.error.value=null}
async function submit(){
  const branch=editing.value,payload=formPayload(catalogFields.branchProduct,form)
  const result=await mutation.run(()=>branch.assignment?branchProductsApi.update(branch.id,props.product.id,payload):branchProductsApi.attach(branch.id,{product_uuid:props.product.id,...payload}))
  if(result.ok){editing.value=null;notify('تم حفظ سعر وتوفر المنتج في الفرع.');emit('changed')}
}
async function detach(branch){if(!(await confirmAction('فك ربط هذا المنتج بفرع '+branch.name+'؟')))return;const result=await mutation.run(()=>branchProductsApi.detach(branch.id,props.product.id));if(result.ok){notify('تم فك الربط.');emit('changed')}}
</script>
<template><section class="panel catalog-section"><AdminSectionHeading icon="map-pin">الفروع والأسعار</AdminSectionHeading><p v-if="!auth.can('branches.view')" class="form-note">عرض الفروع يحتاج صلاحية branches.view.</p><template v-else><ApiFeedback :loading="state.loading.value" :error="state.error.value" :empty="!!state.data.value&&!state.data.value.data.length" @retry="load"/><div v-if="state.data.value?.data.length" class="table-wrap"><table v-admin-table><thead><tr><th>الفرع</th><th>السعر الخاص</th><th>السعر الفعلي</th><th>توفر الفرع / الفعلي</th><th>الإجراءات</th></tr></thead><tbody><tr v-for="branch in state.data.value.data" :key="branch.id"><td><div class="admin-cell-value">{{ branch.name }}<br><small>{{ branch.is_active?'نشط':'متوقف' }}</small></div></td><td><div class="admin-cell-value">{{ !branch.assignment?'غير مرتبط':branch.assignment.price_override==null?'السعر الأساسي':formatCurrency(branch.assignment.price_override) }}</div></td><td><div class="admin-cell-value">{{ branch.assignment?formatCurrency(branch.assignment.effective_price):'—' }}</div></td><td><div class="admin-cell-value">{{ branch.assignment?(branch.assignment.is_available?'متوفر بالفرع':'متوقف بالفرع'):'لا يظهر في منيو الفرع' }}<br><small v-if="branch.assignment">{{ branch.assignment.effective_is_available?'متاح فعليًا':'غير متاح فعليًا' }}</small></div></td><td v-if="auth.can('branches.products.manage')"><div class="admin-cell-value"><div class="admin-row-actions"><button class="row-button" :disabled="mutation.busy.value" @click="edit(branch)">{{ branch.assignment?'تعديل':'ربط بالفرع' }}</button><button v-if="branch.assignment" class="row-button danger" :disabled="mutation.busy.value" @click="detach(branch)"><AdminIcon name="x"/> فك الربط</button></div></div></td></tr></tbody></table></div><ApiPagination :meta="state.data.value?.meta" :links="state.data.value?.links" :busy="state.loading.value" @page="page=$event"/></template>
<form v-if="editing" class="catalog-subform" @submit.prevent="submit"><h3>{{ editing.name }}</h3><CatalogFields :fields="catalogFields.branchProduct" :form="form" :errors="mutation.error.value?.errors" :disabled="mutation.busy.value"/><div class="form-actions"><button class="button" :disabled="mutation.busy.value"><AdminIcon name="badge-check"/> حفظ</button><button class="row-button" type="button" :disabled="mutation.busy.value" @click="editing=null">إلغاء</button></div></form><ApiFeedback v-if="mutation.error.value" :error="mutation.error.value" @retry="mutation.error.value=null"/></section></template>

