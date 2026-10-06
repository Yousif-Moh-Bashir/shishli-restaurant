<script setup>
import {inject as injectAdminConfirmation} from 'vue'
const {confirmAction}=injectAdminConfirmation('admin')
import AdminFilterSummary from '../AdminFilterSummary.vue'
import AdminPageHeader from '../AdminPageHeader.vue'
import {normalizeApiError} from '../../../utils/apiError'
import AppSkeleton from "../../common/AppSkeleton.vue"
import AdminSectionHeading from '../AdminSectionHeading.vue'
import AdminFilterExtras from '../AdminFilterExtras.vue'
import { reactive, ref, computed, watch, onBeforeUnmount } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '../../../stores/auth'
import { useApiState, useApiMutation } from '../../../composables/useApiState'
import { productsApi } from '../../../api/products.api'
import { branchesApi } from '../../../api/branches.api'
import { categoriesApi, flattenCategories } from '../../../api/categories.api'
import { optionGroupsApi } from '../../../api/productOptions.api'
import { formatCurrency } from '../../../utils/formatters'
import AdminIcon from '../AdminIcon.vue'
import ProductImage from './ProductImage.vue'
import CatalogEditor from './CatalogEditor.vue'
import CategoryTree from './CategoryTree.vue'
import ApiFeedback from './ApiFeedback.vue'
import ApiPagination from './ApiPagination.vue'
const props=defineProps({kind:String})
const configs={
  products:{title:'المنتجات',subtitle:'إدارة أصناف المنيو والأسعار والتوفر.',api:productsApi,permission:'products',detail:'/admin/products/'},
  categories:{title:'التصنيفات',subtitle:'نظّم أصناف المنيو وأدر التصنيفات المرتبطة بمنتجاتك.',api:categoriesApi,permission:'categories'},
  branches:{title:'الفروع',subtitle:'إدارة الفروع ووسائل التواصل واستقبال الطلبات.',api:branchesApi,permission:'branches',detail:'/admin/branches/'},
  groups:{title:'خيارات المنتجات',subtitle:'مجموعات قابلة لإعادة الاستخدام وقيمها وأسعار الإضافات.',api:optionGroupsApi,permission:'options',detail:'/admin/product-options/'},
}
const config=computed(()=>configs[props.kind]),auth=useAuthStore(),state=useApiState(),mutation=useApiMutation()
const filters=reactive({search:'',active:'',category_uuid:'',branch_uuid:'',is_available:'',is_featured:'',tracks_inventory:'',type:'',sort:'sort_order',direction:'asc',per_page:20})
const filterDefaults={...filters}
const page=ref(1),editorOpen=ref(false),record=ref(null),mode=ref('edit'),selected=ref([]),bulkAction=ref('status:1'),treeNodes=ref([]),choicesError=ref(null),treeLoading=ref(false),recordLoading=ref(false)
const tree=computed(()=>flattenCategories(treeNodes.value))
const advancedOpen=ref(false)
const advancedCount=computed(()=>['is_available','is_featured','tracks_inventory'].filter(key=>filters[key]!=='').length)
let timer
function params(){return Object.fromEntries(Object.entries({...filters,page:page.value}).filter(([key,value])=>value!==''&&(props.kind==='products'?!['type'].includes(key):props.kind==='groups'?['search','active','type','sort','direction','per_page','page'].includes(key):['search','active','sort','direction','per_page','page'].includes(key))))}
function load(){selected.value=[];state.run(signal=>config.value.api.list(params(),signal))}
watch(filters,()=>{state.clear();clearTimeout(timer);timer=setTimeout(()=>{page.value=1;load()},300)})
watch(page,load)
async function open(item=null,action='edit'){
  mode.value=action;record.value=null
  if(item){recordLoading.value=true;try{const result=await mutation.run(()=>config.value.api.show(item.id));if(!result.ok)return;record.value=result.data}finally{recordLoading.value=false}}
  editorOpen.value=true
}
async function bulk(){
  if(!selected.value.length||!(await confirmAction('تطبيق التغيير على المنتجات المحددة؟')))return
  const [operation,value]=bulkAction.value.split(':')
  const result=await mutation.run(()=>productsApi.bulk(operation,selected.value,value==='1'))
  if(result.ok)load()
}
async function loadTree(){
  if(!auth.can('categories.view'))return
  choicesError.value=null;treeLoading.value=true
  try{treeNodes.value=await categoriesApi.tree()}catch(e){choicesError.value=normalizeApiError(e)}finally{treeLoading.value=false}
}
load()
if(['products','categories'].includes(props.kind))loadTree()
onBeforeUnmount(()=>clearTimeout(timer))
</script>
<template><main id="main" :aria-busy="state.loading.value"><AdminPageHeader class="page-heading"><div><span class="eyebrow">إدارة شيشلي</span><h1>{{ config.title }}</h1><p id="subtitle">{{ config.subtitle }}</p></div><button v-if="auth.can(config.permission+'.create')" class="button" :disabled="mutation.busy.value" @click="open()"><AdminIcon name="plus"/> إضافة {{ kind==='products'?'منتج':kind==='categories'?'تصنيف':kind==='branches'?'فرع':'مجموعة' }}</button></AdminPageHeader>
<div id="content"><section class="panel" :class="{'products-panel':kind==='products'}"><div class="toolbar">
<input v-model="filters.search" type="search" maxlength="150" aria-label="بحث" placeholder="ابحث هنا…">
<select v-model="filters.active" aria-label="حالة التفعيل"><option value="">كل حالات التفعيل</option><option value="1">نشط</option><option value="0">غير نشط</option></select>
<template v-if="kind==='products'">
<select v-if="auth.can('categories.view')" v-model="filters.category_uuid" aria-label="التصنيف"><option value="">كل التصنيفات</option><option v-for="category in tree" :key="category.id" :value="category.id">{{ category.label }}</option></select>
<button class="row-button" :aria-expanded="advancedOpen" aria-controls="product-filters" @click="advancedOpen=!advancedOpen"><AdminIcon name="settings"/> تصفية وترتيب <span v-if="advancedCount">({{ advancedCount }})</span></button>
</template>

</div>
<AdminFilterExtras v-if="kind!=='products'">
<select v-if="kind==='groups'" v-model="filters.type" aria-label="نوع الخيارات"><option value="">كل الأنواع</option><option value="single">اختيار واحد</option><option value="multiple">اختيارات متعددة</option></select>
<select v-model="filters.sort" aria-label="الترتيب"><option value="sort_order">ترتيب العرض</option><option value="name">الاسم</option><option value="created_at">تاريخ الإنشاء</option><template v-if="kind==='products'"><option value="base_price">السعر الأساسي</option><option value="sku">SKU</option><option value="updated_at">آخر تحديث</option></template></select>
<select v-model="filters.direction" aria-label="اتجاه الترتيب"><option value="asc">تصاعدي</option><option value="desc">تنازلي</option></select>
<select v-model.number="filters.per_page" aria-label="عدد النتائج"><option :value="20">20 نتيجة</option><option :value="50">50 نتيجة</option><option :value="100">100 نتيجة</option></select>
</AdminFilterExtras>
<div v-if="kind==='products'" v-show="advancedOpen" id="product-filters" class="product-filters">
<label v-for="[key,label] in [['is_available','التوفر العالمي'],['is_featured','المنتجات المميزة'],['tracks_inventory','متابعة المخزون']]" :key="key" class="field">{{ label }}<select v-model="filters[key]"><option value="">الكل</option><option value="1">نعم</option><option value="0">لا</option></select></label>
<label class="field">الترتيب<select v-model="filters.sort"><option value="sort_order">ترتيب العرض</option><option value="name">الاسم</option><option value="created_at">تاريخ الإنشاء</option><option value="base_price">السعر الأساسي</option><option value="sku">رمز المنتج</option><option value="updated_at">آخر تحديث</option></select></label>
<label class="field">اتجاه الترتيب<select v-model="filters.direction"><option value="asc">تصاعدي</option><option value="desc">تنازلي</option></select></label>
<label class="field">عدد النتائج<select v-model.number="filters.per_page"><option :value="20">20 نتيجة</option><option :value="50">50 نتيجة</option><option :value="100">100 نتيجة</option></select></label>
</div>
<AdminFilterSummary :filters="filters" :defaults="filterDefaults" :busy="state.loading.value" @clear="Object.assign(filters,filterDefaults)"/><AppSkeleton v-if="treeLoading||recordLoading" variant="inline" label="جارٍ تحميل البيانات…"/><ApiFeedback :loading="state.loading.value" :error="state.error.value" :empty="!!state.data.value&&!state.data.value.data.length" @retry="load"/>
<ApiFeedback v-if="mutation.error.value" :error="mutation.error.value" @retry="mutation.error.value=null"/>
<ApiFeedback v-if="choicesError&&kind!=='categories'" :error="choicesError" @retry="loadTree"/>
<template v-if="state.data.value"><div v-if="kind==='products'&&auth.can('products.update')&&selected.length" class="toolbar product-bulk"><select v-model="bulkAction" aria-label="إجراء جماعي"><option value="status:1">تفعيل المحدد</option><option value="status:0">إيقاف المحدد</option><option value="availability:1">إتاحة المحدد عالميًا</option><option value="availability:0">إيقاف توفر المحدد عالميًا</option></select><button class="row-button" :disabled="mutation.busy.value" @click="bulk">تطبيق على {{ selected.length }} منتجات</button></div>
<div v-if="kind==='products'&&state.data.value.data.length" class="table-wrap"><table v-admin-table class="products-table"><thead><tr><th v-if="auth.can('products.update')"><span class="selection-label">تحديد</span></th><th>المنتج</th><th>التصنيف</th><th>السعر الأساسي</th><th>التوفر</th><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody>
<tr v-for="item in state.data.value.data" :key="item.id">
<td v-if="auth.can('products.update')"><div class="admin-cell-value"><input v-model="selected" type="checkbox" :value="item.id" :aria-label="'تحديد '+item.name"></div></td>
<td><div class="admin-cell-value"><div class="product-cell"><ProductImage :image="item.primary_image?.url" :name="item.name"/><div><RouterLink class="product-name" :to="config.detail+item.id">{{ item.name }}</RouterLink><small class="product-description">{{ item.short_description || item.description || 'لم يُضف وصف بعد' }}</small><small v-if="item.sku" class="product-sku">رمز المنتج: <bdi>{{ item.sku }}</bdi></small></div></div></div></td>
<td><div class="admin-cell-value">{{ item.category?.name || '—' }}</div></td><td class="product-price"><div class="admin-cell-value">{{ formatCurrency(item.base_price) }}</div></td>
<td><div class="admin-cell-value"><span class="badge" :class="item.is_available?'good':'off'">{{ item.is_available?'متوفر':'غير متوفر' }}</span><small class="product-description">التوفر العالمي</small><small v-if="item.tracks_inventory" class="product-description">متابعة المخزون</small></div></td>
<td><div class="admin-cell-value"><span class="badge" :class="item.is_active?'good':'off'">{{ item.is_active?'نشط':'متوقف' }}</span><small v-if="item.is_featured" class="product-featured"><AdminIcon name="badge-check"/> مميز</small></div></td>
<td><div class="admin-cell-value"><div class="product-actions"><button v-if="auth.can('products.update')" class="row-button" :disabled="mutation.busy.value" @click="open(item)"><AdminIcon name="pencil"/> تعديل</button><button v-if="auth.can('products.delete')" class="row-button danger" :disabled="mutation.busy.value" @click="open(item,'delete')"><AdminIcon name="trash-2"/> حذف</button><RouterLink class="product-detail" :to="config.detail+item.id">التفاصيل والإدارة <AdminIcon name="arrow-left"/></RouterLink></div></div></td>
</tr></tbody></table></div>
<div v-if="kind!=='products'&&state.data.value.data.length" class="table-wrap"><table v-admin-table class="catalog-table"><thead><tr><th>{{ kind==='categories'?'التصنيف':kind==='branches'?'الفرع':'المجموعة' }}</th><template v-if="kind==='categories'"><th>الوصف</th><th>القسم الأب</th><th>عدد المنتجات</th></template><template v-else-if="kind==='branches'"><th>العنوان والتواصل</th><th>الخدمات</th><th>عدد المنتجات</th></template><template v-else><th>نوع الاختيار</th><th>الحدود والإلزام</th></template><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody><tr v-for="item in state.data.value.data" :key="item.id">
<td><div class="admin-cell-value"><div class="catalog-name"><span class="stat-icon"><AdminIcon :name="kind==='categories'?'layout-grid':kind==='branches'?'map-pin':'settings'"/></span><div><strong>{{ item.name }}</strong><small v-if="kind!=='categories'" class="product-description">{{ item.description || item.slug }}</small></div></div></div></td>
<template v-if="kind==='categories'"><td class="category-description"><div class="admin-cell-value">{{ item.description || '—' }}</div></td><td><div class="admin-cell-value">{{ item.parent?.name || 'قسم رئيسي' }}</div></td><td><div class="admin-cell-value">{{ item.products_count ?? 0 }}</div></td></template>
<template v-else-if="kind==='branches'"><td class="category-description"><div class="admin-cell-value">{{ item.location?.address || [item.location?.city,item.location?.district].filter(Boolean).join('، ') || '—' }}<small class="product-description" dir="ltr">{{ item.contact?.phone || '—' }}</small></div></td><td><div class="admin-cell-value"><div class="catalog-tags"><span v-if="item.supports_pickup" class="badge">استلام</span><span v-if="item.supports_delivery" class="badge">توصيل</span></div><small class="product-description">{{ item.accepts_orders?'يستقبل الطلبات':'استقبال الطلبات متوقف' }}</small></div></td><td><div class="admin-cell-value">{{ item.products_count ?? 0 }}</div></td></template>
<template v-else><td><div class="admin-cell-value">{{ item.type==='single'?'اختيار واحد':'اختيارات متعددة' }}</div></td><td><div class="admin-cell-value">{{ item.min_select }}–{{ item.max_select ?? 'بلا حد' }}<small class="product-description">{{ item.is_required?'إلزامي':'اختياري' }}</small></div></td></template>
<td><div class="admin-cell-value"><span class="badge" :class="item.is_active?'good':'off'">{{ item.is_active?'نشط':'متوقف' }}</span></div></td><td><div class="admin-cell-value"><div class="catalog-actions"><button v-if="auth.can(config.permission+'.update')" class="row-button" :disabled="mutation.busy.value" @click="open(item)"><AdminIcon name="pencil"/> تعديل</button><button v-if="auth.can(config.permission+'.delete')" class="row-button danger" :disabled="mutation.busy.value" @click="open(item,'delete')"><AdminIcon name="trash-2"/> حذف</button><RouterLink v-if="config.detail" class="row-button" :to="config.detail+item.id">التفاصيل والإدارة <AdminIcon name="arrow-left"/></RouterLink></div></div></td>
</tr></tbody></table></div><ApiPagination :meta="state.data.value.meta" :links="state.data.value.links" :busy="state.loading.value" @page="page=$event"/></template>
</section><CategoryTree v-if="kind==='categories'&&auth.can('categories.view')" :nodes="treeNodes" :loading="treeLoading" :error="choicesError" :busy="mutation.busy.value||recordLoading" :can-edit="auth.can('categories.update')" :can-delete="auth.can('categories.delete')" @retry="loadTree" @edit="open($event)" @delete="open($event,'delete')"/></div></main>
<CatalogEditor v-model:open="editorOpen" :record="record" :mode="mode" :kind="kind" :api="config.api" @saved="load(); ['products','categories'].includes(kind)&&loadTree()"/>
</template>

<style scoped>
.products-panel > .toolbar{gap:12px;flex-wrap:wrap}
.products-panel > .toolbar > input[type="search"]{flex:1 1 230px;min-width:0}
.products-panel > .toolbar > .button{margin-inline-start:auto}
.product-filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;padding:18px;margin-bottom:18px;background:#f8efe6;border:1px solid var(--line);border-radius:8px}
.product-filters .field{margin:0;min-width:0}
.product-filters select{width:100%}
.product-bulk{padding:12px;background:#f8efe6;border-radius:8px}
.products-table{min-width:960px}
.products-table .product-cell{min-width:240px}
.product-name{font-weight:700;color:var(--primary);text-decoration:none}
.product-name:hover{text-decoration:underline}
.products-table .product-description{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.product-sku{display:block;font-size:10px;color:var(--muted);margin-top:4px}
.product-price{font-weight:700;white-space:nowrap}
.product-featured{display:flex;align-items:center;gap:4px;font-size:11px;color:var(--primary);margin-top:6px}
.product-featured .icon{width:14px;height:14px}
.product-actions{display:flex;flex-wrap:wrap;gap:6px;min-width:156px;max-width:180px}
.product-actions .row-button{margin:0}
.product-detail{display:flex;align-items:center;gap:6px;color:var(--primary);font-size:11px;text-decoration:none;margin-top:4px}
.product-detail:hover{text-decoration:underline}
.product-detail .icon{width:14px;height:14px}
.products-table input[type="checkbox"]{width:16px;height:16px;accent-color:var(--primary)}
@media(max-width:700px){.product-filters{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:12px}.products-panel > .toolbar > input[type="search"]{flex-basis:100%}.products-panel > .toolbar > select{flex:1;min-width:0}.products-panel > .toolbar > .button{margin-inline-start:0}}
@media(max-width:400px){.product-filters{grid-template-columns:1fr}}
</style>
