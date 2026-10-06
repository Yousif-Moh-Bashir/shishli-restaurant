<script setup>
import {inject as injectAdminConfirmation} from 'vue'
const {confirmAction}=injectAdminConfirmation('admin')
import {normalizeApiError} from '../../../utils/apiError'
import { reactive, watch, inject, ref, computed } from 'vue'
import AdminModal from '../AdminModal.vue'
import CatalogFields from './CatalogFields.vue'
import ApiFeedback from './ApiFeedback.vue'
import { useApiMutation } from '../../../composables/useApiState'
import { catalogFields, initialForm, formPayload } from '../../../constants/catalogFields'
import { categoriesApi, flattenCategories } from '../../../api/categories.api'
import { useAuthStore } from '../../../stores/auth'
import AppSkeleton from '../../common/AppSkeleton.vue'
const props = defineProps({ open:Boolean, record:Object, mode:String, kind:String, api:Object })
const emit = defineEmits(['update:open','saved'])
const { notify } = inject('admin')
const auth = useAuthStore(), mutation = useApiMutation(), form = reactive({}), categories = ref([]), choicesError = ref(null)
const kindLabels={products:'منتج',categories:'تصنيف',branches:'فرع',groups:'مجموعة خيارات'}
const fields = computed(()=>catalogFields[props.kind]),choicesLoading=ref(false)
const sections=computed(()=>({
 products:[{title:'المعلومات الأساسية',keys:['name','slug','sku','category_uuid']},{title:'التسعير والتجهيز',keys:['base_price','preparation_time']},{title:'وصف المنتج',keys:['short_description','description']},{title:'التوفر والعرض',keys:['sort_order','is_active','is_available','is_featured','tracks_inventory']}],
 branches:[{title:'بيانات الفرع',keys:['name','slug','sort_order']},{title:'التواصل',keys:['phone','whatsapp']},{title:'الموقع',keys:['city','district','address','latitude','longitude']},{title:'الحالة والخدمات',keys:['is_active','accepts_orders','supports_pickup','supports_delivery']}],
}[props.kind]))
watch(()=>props.open,async open=>{
  if(!open)return
  mutation.error.value=null;choicesError.value=null
  const record={...props.record}
  if(props.kind==='branches')Object.assign(record,record.contact,record.location)
  if(props.kind==='products')record.category_uuid=record.category?.id
  if(props.kind==='categories')record.parent_uuid=record.parent?.id
  Object.assign(form,initialForm(fields.value,record))
  if(props.kind==='products'&&props.mode!=='delete'&&!form.sku){
    const values=new Uint32Array(1)
    do{crypto.getRandomValues(values)}while(values[0]>=4294800000)
    form.sku='SH-'+String(100000+values[0]%900000)
  }
  if(props.mode!=='delete'&&['products','categories'].includes(props.kind)){
    choicesLoading.value=true
    categories.value=[]
    try { categories.value=flattenCategories(await (auth.can('categories.view')?categoriesApi.tree():categoriesApi.publicList())).filter(category=>category.id!==props.record?.id)
      if(props.kind==='products'&&record.category&&!categories.value.some(c=>c.id===record.category.id))categories.value.push(record.category)
    } catch(e) { choicesError.value=normalizeApiError(e) } finally {choicesLoading.value=false}
  }
})
async function submit(){
  const payload=formPayload(fields.value,form)
  if(props.kind==='branches'&&props.mode!=='delete'&&props.record&&['accepts_orders','supports_pickup','supports_delivery'].some(key=>props.record[key]===true&&payload[key]===false)&&!(await confirmAction('قد يؤدي تعطيل استقبال الطلبات أو الاستلام أو التوصيل إلى توقف الطلبات الجديدة لهذا الفرع. هل تريد الحفظ؟')))return
  if(props.kind==='branches'&&props.record&&!payload.slug)delete payload.slug
  const result=await mutation.run(()=>props.mode==='delete'?props.api.remove(props.record.id):props.record?props.api.update(props.record.id,payload):props.api.create(payload))
  if(result.ok){notify(props.mode==='delete'?'تم الحذف على الخادم.':'تم حفظ البيانات على الخادم.');emit('saved',result.data);emit('update:open',false)}
}
</script>
<template><AdminModal :open="open" :size="mode!=='delete'&&['products','branches'].includes(kind)?'large':'medium'" :class="{'product-editor-dialog':kind==='products'&&mode!=='delete'}" :title="mode==='delete'?'تأكيد الحذف':(record?'تعديل ':'إضافة ')+(kindLabels[kind]||'بيانات')" :busy="mutation.busy.value" @update:open="$emit('update:open',$event)" @submit="submit">
<p v-if="mode==='delete'">هل تريد حذف «{{ record?.name }}»؟ سيطبق الخادم قواعد الحذف.</p>
<template v-else><AppSkeleton v-if="choicesLoading" variant="inline" label="جارٍ تحميل التصنيفات…"/><ApiFeedback v-if="choicesError" :error="choicesError" @retry="emit('update:open',false)"/><CatalogFields :sections="sections" :fields="fields" :form="form" :categories="categories" :errors="mutation.error.value?.errors" :disabled="mutation.busy.value"/><p v-if="kind==='categories'" class="form-note">الصورة حقل رابط أو مسار؛ لا يوفر الخادم رفعًا لصور الأقسام.</p><p v-if="kind==='products'" class="form-note">يمكن إدارة الصور والخيارات وأسعار الفروع بعد إنشاء المنتج. تفعيل تتبع المخزون يحتاج إعداد المخزون في مرحلة إدارته.</p></template>
<div v-if="mutation.error.value" class="form-note" role="alert">{{ mutation.error.value.message }}<ul><li v-for="(messages,field) in mutation.error.value.errors" :key="field">{{ messages.join(' ') }}</li></ul></div>
<div class="form-actions"><button class="button" :class="{danger:mode==='delete'}" type="submit" :disabled="mutation.busy.value">{{ mutation.busy.value?'جارٍ الحفظ…':mode==='delete'?'تأكيد الحذف':'حفظ البيانات' }}</button><button class="row-button" type="button" :disabled="mutation.busy.value" @click="emit('update:open',false)">إلغاء</button></div>
</AdminModal></template>
