<script setup>
import AdminIcon from '../AdminIcon.vue'
import AdminSectionHeading from '../AdminSectionHeading.vue'
import { ref, computed, onBeforeUnmount, inject, useId, watch } from 'vue'
import { productImagesApi } from '../../../api/productImages.api'
import { useApiMutation } from '../../../composables/useApiState'
import { useAuthStore } from '../../../stores/auth'
import ApiFeedback from './ApiFeedback.vue'
import ProductImage from './ProductImage.vue'
const props=defineProps({product:Object}),emit=defineEmits(['changed'])
const auth=useAuthStore(),mutation=useApiMutation(),files=ref([]),previews=ref([]),alt=ref(''),primary=ref(false),input=ref(null),localError=ref('')
const {notify,confirmAction}=inject('admin')
const images=computed(()=>props.product.images||[])
const uploadId=useId(),dragging=ref(false)
const sizeLabel=size=>size<1024*1024?Math.ceil(size/1024)+' كيلوبايت':(size/(1024*1024)).toFixed(1)+' ميجابايت'
function clear(){previews.value.forEach(url=>URL.revokeObjectURL(url));previews.value=[];files.value=[];if(input.value)input.value.value=''}
function choose(event){selectFiles(Array.from(event.target.files || []))}
function selectFiles(selected){
  if(mutation.busy.value)return
  localError.value=''
  if(!selected.length)return
  if(selected.length>10||selected.some(file=>!['image/jpeg','image/png','image/webp'].includes(file.type)||file.size>5120*1024)){if(input.value)input.value.value='';localError.value='اختر حتى 10 صور JPG أو PNG أو WebP، كل صورة حتى 5 ميجابايت.';return}
  if(images.value.length+selected.length>20){if(input.value)input.value.value='';localError.value='الحد الأقصى 20 صورة للمنتج. اختر عددًا أقل أو احذف صورة محفوظة أولًا.';return}
  clear()
  files.value=selected;previews.value=selected.map(file=>URL.createObjectURL(file))
}
function drop(event){dragging.value=false;if(!mutation.busy.value)selectFiles(Array.from(event.dataTransfer?.files||[]))}
function removeSelected(index){if(mutation.busy.value)return;URL.revokeObjectURL(previews.value[index]);previews.value.splice(index,1);files.value.splice(index,1);if(input.value)input.value.value='';localError.value=''}
function upload(){if(!files.value.length||mutation.busy.value)return;return act(()=>productImagesApi.upload(props.product.id,files.value,alt.value,primary.value),true)}
async function act(request,upload=false){const result=await mutation.run(request);if(result.ok){if(upload)clear();notify('تم تحديث صور المنتج على الخادم.');emit('changed')}}
function move(index,step){const order=[...images.value];[order[index],order[index+step]]=[order[index+step],order[index]];act(()=>productImagesApi.reorder(props.product.id,order))}
async function remove(image){if(await confirmAction('حذف هذه الصورة؟'))act(()=>productImagesApi.remove(props.product.id,image.id))}
onBeforeUnmount(clear)
watch(()=>props.product.id,()=>{clear();alt.value='';primary.value=false;localError.value=''})
</script>
<template><section class="panel catalog-section"><AdminSectionHeading icon="utensils">صور المنتج</AdminSectionHeading><p class="form-note">حتى 20 صورة للمنتج؛ الرفع حتى 10 ملفات، كل ملف حتى 5 ميجابايت.</p><div class="catalog-image-grid"><article v-for="(image,index) in images" :key="image.id"><ProductImage :image="image.url" :name="image.alt_text||product.name"/><p>{{ image.is_primary?'الصورة الرئيسية':'صورة '+(index+1) }}</p><div v-if="auth.can('products.update')" class="catalog-actions"><button class="row-button" :disabled="mutation.busy.value||image.is_primary" @click="act(()=>productImagesApi.primary(product.id,image.id))">تعيين رئيسية</button><button class="row-button" :disabled="mutation.busy.value||index===0" aria-label="تقديم الصورة" @click="move(index,-1)">↑</button><button class="row-button" :disabled="mutation.busy.value||index===images.length-1" aria-label="تأخير الصورة" @click="move(index,1)">↓</button><button class="row-button danger" :disabled="mutation.busy.value" @click="remove(image)"><AdminIcon name="trash-2"/> حذف</button></div></article></div><p v-if="!images.length" class="empty">لا توجد صور لهذا المنتج.</p>
<form v-if="auth.can('products.update')" class="product-image-upload" @submit.prevent="upload">
  <h3>إضافة صور المنتج</h3>
  <div class="image-upload-zone" :class="{'is-dragging':dragging,'is-disabled':mutation.busy.value}" @dragover.prevent="dragging=!mutation.busy.value" @dragleave.prevent="dragging=false" @drop.prevent="drop">
    <span class="image-upload-symbol"><AdminIcon name="upload"/></span>
    <strong>اسحب صور المنتج إلى هنا</strong><p>أو اختر الصور من جهازك</p>
    <input :id="uploadId" ref="input" class="image-upload-input" type="file" accept="image/jpeg,image/png,image/webp" multiple tabindex="-1" :disabled="mutation.busy.value" :aria-describedby="uploadId+'-help'" aria-label="إضافة صور المنتج" @change="choose">
    <button class="row-button" type="button" :disabled="mutation.busy.value" :aria-describedby="uploadId+'-help'" @click="input?.click()">{{ files.length?'تغيير الصور المختارة':'اختيار صور' }}</button>
    <small :id="uploadId+'-help'">JPG، PNG أو WebP · حتى 10 صور في الرفع · 5 ميجابايت للصورة</small>
  </div>
  <div v-if="files.length" class="image-selection-heading"><span role="status">{{ files.length }} صور جاهزة للرفع</span><button class="text-button" type="button" :disabled="mutation.busy.value" @click="clear">مسح الاختيار</button></div>
  <ul v-if="files.length" class="image-selection-grid" aria-label="معاينة الصور قبل الرفع"><li v-for="(file,index) in files" :key="previews[index]"><div class="image-selection-preview"><img :src="previews[index]" :alt="'معاينة '+file.name"><span v-if="primary&&index===0" class="image-primary-label">الصورة الرئيسية</span><button class="image-selection-remove" type="button" :disabled="mutation.busy.value" :aria-label="'إزالة '+file.name+' من الاختيار'" @click="removeSelected(index)"><AdminIcon name="x"/></button></div><strong :title="file.name">{{ file.name }}</strong><small>{{ sizeLabel(file.size) }}</small></li></ul>
  <div class="form-grid"><label class="field">النص البديل للصورة<input v-model="alt" maxlength="255" placeholder="وصف مختصر للصورة" :disabled="mutation.busy.value"></label><label class="check"><input v-model="primary" type="checkbox" :disabled="mutation.busy.value">تعيين الصورة الأولى كرئيسية</label></div>
  <p v-if="localError" class="field-error" role="alert">{{ localError }}</p>
  <div class="image-upload-actions"><p>تظهر المعاينات هنا قبل الحفظ؛ اضغط رفع الصور لإضافتها للمنتج.</p><button class="button" :disabled="!files.length||mutation.busy.value">{{ mutation.busy.value?'جارٍ الرفع…':'رفع الصور' }}</button></div>
</form>
<ApiFeedback v-if="mutation.error.value" :error="mutation.error.value" @retry="mutation.error.value=null"/></section></template>

<style scoped>
.product-image-upload{border-top:1px solid var(--line);padding-top:22px;margin-top:20px}
.product-image-upload>h3{font-size:16px;margin:0 0 14px;color:var(--primary)}
.image-upload-zone{display:flex;flex-direction:column;align-items:center;gap:7px;padding:26px 20px;border:1px dashed #cbbeb5;border-radius:12px;background:#fafafa;text-align:center}
.image-upload-zone.is-dragging{border-color:var(--primary);background:#f7f0f1}.image-upload-zone.is-disabled{opacity:.65}
.image-upload-symbol{display:grid;place-items:center;width:44px;height:44px;background:#f0ebea;border-radius:12px;color:var(--primary)}.image-upload-symbol .icon{width:24px;height:24px}
.image-upload-zone strong{font-size:15px}.image-upload-zone p{margin:0;font-size:13px;color:var(--muted)}.image-upload-zone small{font-size:11px;color:var(--muted);margin-top:5px}
.image-upload-zone .row-button{margin:5px 0 0;padding:9px 20px;min-height:40px}
.image-upload-input{position:absolute;width:1px;height:1px;padding:0;border:0;overflow:hidden;clip-path:inset(50%)}
.image-selection-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;font-size:12px;margin-top:18px;color:var(--muted)}
.image-selection-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:14px;list-style:none;padding:0;margin:12px 0 20px}
.image-selection-grid li{min-width:0;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:#fff;padding-bottom:10px}
.image-selection-preview{position:relative;background:#f4f4f4;height:125px}.image-selection-preview img{width:100%;height:100%;object-fit:contain;display:block}
.image-selection-grid li>strong{display:block;padding:9px 10px 3px;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;direction:auto}.image-selection-grid li>small{display:block;padding-inline:10px;color:var(--muted);font-size:11px}
.image-selection-remove{position:absolute;top:6px;left:6px;width:30px;height:30px;border:1px solid var(--line);border-radius:50%;background:#fff;display:grid;place-items:center;color:var(--primary)}.image-selection-remove .icon{width:16px;height:16px}
.image-primary-label{position:absolute;bottom:6px;right:6px;background:#fff;padding:3px 7px;border-radius:5px;font-size:10px;color:var(--primary)}
.product-image-upload>.form-grid{margin-top:20px;align-items:center}.image-upload-actions{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:18px}.image-upload-actions p{font-size:12px;color:var(--muted);margin:0}.image-upload-actions .button{flex-shrink:0;background:#d71920;border-color:#d71920;color:white;min-height:44px}
@media(max-width:600px){.image-upload-zone{padding:22px 14px}.image-selection-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.image-upload-actions{align-items:stretch;flex-direction:column}.image-upload-actions .button{width:100%}}
</style>
