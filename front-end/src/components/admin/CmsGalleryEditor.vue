<script setup>
import {computed,ref,watch,onBeforeUnmount,useId} from 'vue'
import AdminIcon from './AdminIcon.vue'
import DomainFields from './DomainFields.vue'
import {safeContentUrl} from '../../utils/contentUrl'
const props=defineProps({form:Object,fields:Array,errors:Object,disabled:Boolean,existingImage:String})
const input=ref(null),preview=ref(''),localError=ref(''),depth=ref(0),id=useId()
const groups=[{title:'بيانات الصورة',keys:['title','description','alt_text']},{title:'النشر والترتيب',keys:['published_at','sort_order','is_active']}]
const otherFields=computed(()=>props.fields.filter(field=>field.key!=='image'))
const imageRequired=computed(()=>props.fields.find(field=>field.key==='image')?.required&&!props.form.image)
const imageErrors=computed(()=>props.errors?.image||[])
const currentImage=computed(()=>safeContentUrl(props.existingImage))
const source=computed(()=>preview.value||currentImage.value)
const size=computed(()=>props.form.image?(props.form.image.size/1024/1024).toFixed(2)+' ميجابايت':'')
function revoke(){if(preview.value)URL.revokeObjectURL(preview.value);preview.value=''}
watch(()=>props.form.image,file=>{revoke();localError.value='';if(input.value)input.value.value='';if(file instanceof File)preview.value=URL.createObjectURL(file)},{immediate:true})
onBeforeUnmount(revoke)
function select(files){
 if(props.disabled)return
 if(input.value)input.value.value=''
 if(!files.length)return
 if(files.length!==1){localError.value='اختر صورة واحدة لكل عنصر في المعرض.';return}
 const file=files[0]
 if(!['image/jpeg','image/png','image/webp'].includes(file.type)){localError.value='اختر صورة بصيغة JPEG أو PNG أو WebP.';return}
 if(file.size>5*1024*1024){localError.value='حجم الصورة يجب ألا يتجاوز 5 ميجابايت.';return}
 props.form.image=file;localError.value=''
}
function drop(event){depth.value=0;select(Array.from(event.dataTransfer?.files||[]))}
function clear(){if(props.disabled)return;props.form.image=null;localError.value='';if(input.value)input.value.value=''}
</script>

<template>
 <div class="cms-gallery-editor">
  <section class="gallery-media-panel" aria-label="صورة المعرض">
   <div class="gallery-upload-zone" :class="{'is-dragging':depth>0&&!disabled,'is-disabled':disabled}" @dragenter.prevent="depth++" @dragover.prevent @dragleave.prevent="depth=Math.max(0,depth-1)" @drop.prevent.stop="drop">
    <div v-if="source" class="gallery-image-preview"><img :src="source" :alt="form.alt_text||form.title||'معاينة صورة المعرض'"><span class="gallery-preview-label">{{ preview?'معاينة قبل الحفظ':'الصورة الحالية' }}</span></div>
    <span v-else class="gallery-upload-icon"><AdminIcon name="image"/></span>
    <strong>{{ source?'اسحب صورة جديدة لاستبدالها':'اسحب الصورة وأفلتها هنا' }}</strong>
    <p>أو اختر صورة من جهازك</p>
    <input :id="id" ref="input" class="gallery-file-input" type="file" accept="image/jpeg,image/png,image/webp" :required="imageRequired" :disabled="disabled" :aria-describedby="id+'-help '+id+'-errors'" aria-label="اختيار صورة المعرض" @change="select(Array.from($event.target.files||[]))" @invalid.prevent="localError='اختر صورة للمعرض قبل الحفظ.'">
    <button class="row-button" type="button" :disabled="disabled" @click="input?.click()"><AdminIcon name="upload"/> {{ source?'تغيير الصورة':'اختيار صورة' }}</button>
    <small :id="id+'-help'">JPEG، PNG أو WebP · صورة واحدة حتى 5 ميجابايت</small>
   </div>
   <div v-if="form.image" class="gallery-selected-file"><div role="status"><strong :title="form.image.name">{{ form.image.name }}</strong><small>{{ size }}</small></div><button class="row-button" type="button" :disabled="disabled" @click="clear">{{ currentImage?'التراجع عن الاستبدال':'مسح الاختيار' }}</button></div>
   <div :id="id+'-errors'"><p v-if="localError" class="field-error" role="alert">{{ localError }}</p><p v-for="error in imageErrors" :key="error" class="field-error" role="alert">{{ error }}</p></div>
   <p class="gallery-media-note">{{ currentImage?'تبقى الصورة الحالية محفوظة حتى تحفظ صورة بديلة.':'تظهر الصورة في المعرض بعد الحفظ والتفعيل وحلول موعد النشر.' }}</p>
  </section>
  <DomainFields :sections="groups" :fields="otherFields" :form="form" :errors="errors" :disabled="disabled"/>
 </div>
</template>

<style scoped>
.cms-gallery-editor{display:grid;grid-template-columns:minmax(0,.95fr) minmax(0,1.05fr);gap:24px;align-items:start}
.cms-gallery-editor>*{min-width:0}
.gallery-media-panel{padding:18px;border:1px solid var(--line);border-radius:14px;background:#faf8f6}
.gallery-upload-zone{display:flex;flex-direction:column;align-items:center;gap:9px;padding:20px 14px;border:2px dashed #cbbeb5;border-radius:12px;background:#fff;text-align:center}
.gallery-upload-zone.is-dragging{border-color:var(--primary);background:#f7f0f1}.gallery-upload-zone.is-disabled{opacity:.65}
.gallery-upload-zone strong{font-size:15px;color:var(--primary)}.gallery-upload-zone p{margin:0;font-size:13px;color:var(--muted)}.gallery-upload-zone small{font-size:11px;color:var(--muted);line-height:1.8}
.gallery-upload-icon{display:grid;place-items:center;width:58px;height:58px;margin-bottom:6px;border-radius:16px;color:var(--primary);background:#f7f0f1}.gallery-upload-icon .icon{width:28px;height:28px}
.gallery-upload-zone .row-button{display:inline-flex;align-items:center;gap:7px;min-height:44px;padding:9px 16px}.gallery-upload-zone .row-button .icon{width:18px;height:18px}
.gallery-file-input{position:absolute;width:1px;height:1px;padding:0;border:0;overflow:hidden;clip-path:inset(50%)}
.gallery-image-preview{position:relative;width:100%;aspect-ratio:4/3;background:#f4efea;border-radius:9px;overflow:hidden;margin-bottom:6px}.gallery-image-preview img{display:block;width:100%;height:100%;object-fit:contain}.gallery-preview-label{position:absolute;inset-inline-start:8px;bottom:8px;padding:5px 9px;border-radius:6px;background:#fff;color:var(--primary);font-size:11px}
.gallery-selected-file{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:14px}.gallery-selected-file>div{min-width:0}.gallery-selected-file strong{display:block;font-size:12px;overflow-wrap:anywhere}.gallery-selected-file small{display:block;margin-top:4px;color:var(--muted);font-size:11px}.gallery-selected-file button{flex-shrink:0;min-height:40px}
.gallery-media-note{margin:14px 0 0;font-size:12px;color:var(--muted);line-height:1.8}.gallery-media-panel .field-error{font-size:12px;margin:10px 0 0}
.cms-gallery-editor :deep(.form-grid){grid-template-columns:minmax(0,1fr)}
@media(max-width:760px){.cms-gallery-editor{grid-template-columns:minmax(0,1fr);gap:18px}.gallery-media-panel{padding:12px}.gallery-image-preview{max-height:240px}.gallery-selected-file{align-items:stretch;flex-direction:column}}
</style>
