<script setup>
import {inject as injectAdminConfirmation} from 'vue'
const {confirmAction}=injectAdminConfirmation('admin')
import AdminIcon from '../AdminIcon.vue'
import AdminSectionHeading from '../AdminSectionHeading.vue'
import { ref, reactive, watch, onBeforeUnmount, inject } from 'vue'
import { branchProductsApi } from '../../../api/branches.api'
import { productsApi } from '../../../api/products.api'
import { useAuthStore } from '../../../stores/auth'
import { useApiState,useApiMutation } from '../../../composables/useApiState'
import { catalogFields,initialForm,formPayload } from '../../../constants/catalogFields'
import { formatCurrency } from '../../../utils/formatters'
import RemotePicker from './RemotePicker.vue'
import CatalogFields from './CatalogFields.vue'
import ApiFeedback from './ApiFeedback.vue'
import ApiPagination from './ApiPagination.vue'
const props=defineProps({branch:String})
const state=useApiState(),mutation=useApiMutation(),auth=useAuthStore(),search=ref(''),available=ref(''),page=ref(1),sort=ref('product_name'),direction=ref('asc')
const editing=ref(null),form=reactive(initialForm(catalogFields.branchProduct)),showForm=ref(false),selected=ref(''),bulkOpen=ref(false),bulkIds=ref([])
const {notify}=inject('admin');let timer
function load(){if(props.branch)state.run(signal=>branchProductsApi.list(props.branch,{search:search.value,is_available:available.value,sort:sort.value,direction:direction.value,page:page.value,per_page:20},signal))}
watch(()=>props.branch,()=>{showForm.value=false;bulkOpen.value=false;page.value=1;load()},{immediate:true})
watch([search,available,sort,direction],()=>{state.clear();clearTimeout(timer);timer=setTimeout(()=>{page.value=1;load()},300)})
watch(page,load)
function edit(item=null){editing.value=item;selected.value='';Object.assign(form,initialForm(catalogFields.branchProduct,item||{}));showForm.value=true;bulkOpen.value=false;mutation.error.value=null}
async function submit(){
  const payload=formPayload(catalogFields.branchProduct,form)
  const result=await mutation.run(()=>editing.value?branchProductsApi.update(props.branch,editing.value.product.id,payload):branchProductsApi.attach(props.branch,{...payload,product_uuid:selected.value}))
  if(result.ok){showForm.value=false;notify('تم حفظ إعدادات المنتج في الفرع.');load()}
}
async function detach(item){if(!(await confirmAction('فك ربط «'+item.product.name+'» بهذا الفرع؟ لن يظهر في منيو الفرع.')))return;const result=await mutation.run(()=>branchProductsApi.detach(props.branch,item.product.id));if(result.ok){notify('تم فك الربط.');load()}}
function selectBulk(item,checked){bulkIds.value=checked?[...new Set([...bulkIds.value,item.id])]:bulkIds.value.filter(id=>id!==item.id)}
async function bulk(){
  if(!(await confirmAction('حفظ السعر والتوفر للمنتجات المحددة في هذا الفرع؟')))return
  const payload=formPayload(catalogFields.branchProduct,form)
  const result=await mutation.run(()=>branchProductsApi.bulk(props.branch,bulkIds.value.map(product_uuid=>({product_uuid,...payload}))))
  if(result.ok){bulkOpen.value=false;bulkIds.value=[];notify('تم تحديث منتجات الفرع على الخادم.');load()}
}
onBeforeUnmount(()=>clearTimeout(timer))
</script>
<template><section class="panel catalog-section"><AdminSectionHeading icon="utensils">منتجات الفرع وأسعاره</AdminSectionHeading><div class="toolbar"><input v-model="search" type="search" maxlength="150" placeholder="بحث عن منتج الفرع…" aria-label="بحث منتجات الفرع"><select v-model="available" aria-label="توفر الفرع"><option value="">كل حالات توفر الفرع</option><option value="1">متوفر</option><option value="0">غير متوفر</option></select><select v-model="sort" aria-label="الترتيب"><option value="product_name">اسم المنتج</option><option value="price_override">السعر الخاص</option><option value="is_available">التوفر</option><option value="created_at">تاريخ الربط</option><option value="updated_at">آخر تحديث</option></select><select v-model="direction" aria-label="الاتجاه"><option value="asc">تصاعدي</option><option value="desc">تنازلي</option></select><template v-if="auth.can('branches.products.manage')&&auth.can('products.view')"><button class="row-button" :disabled="mutation.busy.value" @click="edit()">إضافة منتج</button><button class="row-button" :disabled="mutation.busy.value" @click="showForm=false;bulkOpen=true;bulkIds=[];Object.assign(form,initialForm(catalogFields.branchProduct))">إضافة / تحديث جماعي</button></template></div>
<ApiFeedback :loading="state.loading.value" :error="state.error.value" :empty="!!state.data.value&&!state.data.value.data.length" @retry="load"/>
<div v-if="state.data.value?.data.length" class="table-wrap"><table v-admin-table><thead><tr><th>المنتج</th><th>السعر الأساسي</th><th>السعر الخاص</th><th>السعر الفعلي</th><th>التوفر العالمي / الفرع / الفعلي</th><th>الإجراءات</th></tr></thead><tbody><tr v-for="item in state.data.value.data" :key="item.product.id"><td><div class="admin-cell-value">{{ item.product.name }}</div></td><td><div class="admin-cell-value">{{ formatCurrency(item.product.base_price) }}</div></td><td><div class="admin-cell-value">{{ item.price_override==null?'السعر الأساسي':formatCurrency(item.price_override) }}</div></td><td><div class="admin-cell-value">{{ formatCurrency(item.effective_price) }}</div></td><td><div class="admin-cell-value">{{ item.global_is_available?'متوفر عالميًا':'متوقف عالميًا' }}<br>{{ item.is_available?'متوفر بالفرع':'متوقف بالفرع' }}<br><span class="badge" :class="item.effective_is_available?'good':'off'">{{ item.effective_is_available?'متاح فعليًا':'غير متاح فعليًا' }}</span></div></td><td v-if="auth.can('branches.products.manage')"><div class="admin-cell-value"><div class="admin-row-actions"><button class="row-button" :disabled="mutation.busy.value" @click="edit(item)"><AdminIcon name="pencil"/> تعديل</button><button class="row-button danger" :disabled="mutation.busy.value" @click="detach(item)"><AdminIcon name="x"/> فك الربط</button></div></div></td></tr></tbody></table></div>
<ApiPagination :meta="state.data.value?.meta" :links="state.data.value?.links" :busy="state.loading.value" @page="page=$event"/>
<form v-if="showForm" class="catalog-subform" @submit.prevent="submit"><h3>{{ editing?'تعديل '+editing.product.name:'ربط منتج بالفرع' }}</h3><RemotePicker v-if="!editing" v-model="selected" :api="productsApi" label="اختر منتجًا"/><CatalogFields :fields="catalogFields.branchProduct" :form="form" :disabled="mutation.busy.value" :errors="mutation.error.value?.errors"/><div class="form-actions"><button class="button" :disabled="mutation.busy.value||(!editing&&!selected)"><AdminIcon name="badge-check"/> حفظ</button><button class="row-button" type="button" :disabled="mutation.busy.value" @click="showForm=false">إلغاء</button></div></form>
<form v-if="bulkOpen" class="catalog-subform" @submit.prevent="bulk"><h3>إضافة أو تحديث عدة منتجات في الفرع</h3><RemotePicker :api="productsApi" multiple :exclude="bulkIds" label="حدد المنتجات" @select="selectBulk"/><CatalogFields :fields="catalogFields.branchProduct" :form="form" :disabled="mutation.busy.value" :errors="mutation.error.value?.errors"/><p class="form-note">يُطبق السعر والتوفر أعلاه على جميع المنتجات المحددة، سواء كانت مرتبطة مسبقًا أو جديدة.</p><div class="form-actions"><button class="button" :disabled="mutation.busy.value||!bulkIds.length||bulkIds.length>100">حفظ {{ bulkIds.length }} منتجات</button><button class="row-button" type="button" :disabled="mutation.busy.value" @click="bulkOpen=false">إلغاء</button></div></form>
<ApiFeedback v-if="mutation.error.value" :error="mutation.error.value" @retry="mutation.error.value=null"/></section></template>

