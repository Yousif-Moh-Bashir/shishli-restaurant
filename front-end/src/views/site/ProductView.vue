<script setup>
import AppSkeleton from "../../components/common/AppSkeleton.vue"
import {ref,computed,watch,inject} from 'vue'
import {useRoute,RouterLink} from 'vue-router'
import SiteIcon from '../../components/site/SiteIcon.vue'
import BranchSelector from '../../components/site/BranchSelector.vue'
import {useBranchStore} from '../../stores/branch'
import {useCartStore} from '../../stores/cart'
import {menuApi} from '../../api/products.api'
import {useApiState} from '../../composables/useApiState'
import {menuProduct} from '../../composables/usePublicMenu'
import {formatCurrency as money} from '../../utils/formatters'
import {useCartFeedback} from '../../composables/useCartFeedback'
import {setPageSeo,routeSeo} from '../../utils/seo'
const {pending,submit}=useCartFeedback()
const route=useRoute(),branch=useBranchStore(),state=useApiState(),cart=useCartStore(),quantity=ref(1),selections=ref({}),selectedImage=ref(null),optionError=ref('')
const {notifyCartResult,openCart,toast}=inject('site')
const metadata=useApiState()
watch(()=>route.params.slug,async uuid=>{
  if(!uuid||route.name!=='product')return
  const result=await metadata.run(signal=>menuApi.publicProduct(uuid,signal))
  if(result&&route.name==='product'&&route.params.slug===uuid)setPageSeo({...routeSeo(route),title:result.name+' | مطعم شيشلي فرش',description:result.short_description||result.description||result.name,image:result.primary_image?.url})
  else if(metadata.error.value?.status===404&&route.params.slug===uuid)setPageSeo({...routeSeo(route),title:'المنتج غير موجود | مطعم شيشلي فرش',index:false})
},{immediate:true})
const product=computed(()=>state.data.value)
const groups=computed(()=>product.value?.option_groups||[])
const images=computed(()=>[...(product.value?.images||[])].sort((a,b)=>Number(b.is_primary)-Number(a.is_primary)||a.sort_order-b.sort_order))
const image=computed(()=>images.value.find(item=>item.id===selectedImage.value)||images.value[0]||product.value?.primary_image)
const selectedOptions=computed(()=>groups.value.map(group=>({option_group_uuid:group.id,option_value_uuids:selections.value[group.id]||[]})))
const editingItem=computed(()=>cart.items.find(item=>item.id===route.query.cartItem&&item.product?.id===product.value?.id))
async function load(){
  quantity.value=1;selections.value={};selectedImage.value=null;optionError.value=''
  if(!branch.selectedBranch){state.clear();return}
  const result=await state.run(signal=>menuApi.product(route.params.slug,branch.selectedUuid,signal))
  if(result&&state.data.value===result){
    selections.value=Object.fromEntries(result.option_groups.map(group=>[group.id,group.values.filter(value=>value.is_default).map(value=>value.id)]))
    if(route.query.cartItem){await cart.initializeCart();if(editingItem.value){selections.value=Object.fromEntries(groups.value.map(group=>[group.id,editingItem.value.options.filter(option=>option.group.id===group.id).map(option=>option.value.id)]));quantity.value=editingItem.value.quantity}}
  }
}
watch([()=>route.params.slug,()=>route.query.cartItem,()=>branch.selectedUuid],load)
branch.load().then(load)
function toggle(group,value,event){
  const current=selections.value[group.id]||[]
  if(group.type==='single'){selections.value[group.id]=event.target.checked?[value.id]:[];return}
  if(event.target.checked&&group.max_select!=null&&current.length>=group.max_select){event.target.checked=false;toast('الحد الأقصى لمجموعة '+group.name+' هو '+group.max_select);return}
  selections.value[group.id]=event.target.checked?[...current,value.id]:current.filter(id=>id!==value.id)
}
async function add(){
  if(pending.value)return
  optionError.value=''
  for(const group of groups.value){const count=(selections.value[group.id]||[]).length
    if((group.is_required&&count===0)||count<group.min_select||(group.max_select!=null&&count>group.max_select)){optionError.value='راجع اختيارات '+group.name+'؛ الحد الأدنى '+group.min_select+' والحد الأقصى '+(group.max_select??'بلا حد')+'.';return}}
  if(!Number.isInteger(quantity.value)||quantity.value<1||quantity.value>50){toast('اختر كمية صحيحة من 1 إلى 50.');return}
  if(product.value?.available===true){
    const itemToEdit=editingItem.value
    const result=await submit(()=>itemToEdit?cart.updateOptions(itemToEdit.id,selectedOptions.value):cart.addItem(menuProduct(product.value,branch.selectedUuid),quantity.value,selectedOptions.value))
    if(itemToEdit){toast(result.ok?'تم تحديث خيارات السلة.':result.message)}else notifyCartResult(result,product.value.name)
    if(!result.ok)optionError.value=Object.values(cart.error?.errors||{}).flat().join(' ')||result.message
  }
}
</script>
<template><main id="main" class="container product-main"><RouterLink class="auth-back" to="/menu">→ العودة للمنيو</RouterLink><BranchSelector/>
<p v-if="!branch.selectedBranch&&!branch.isLoading&&!branch.error" class="empty-search">اختر الفرع لعرض تفاصيل المنتج.</p><AppSkeleton v-if="branch.isLoading || state.loading.value" variant="detail" label="جارٍ تحميل المنتج…"/>
<section v-else-if="state.error.value" class="checkout-empty" role="alert"><h1>{{ state.error.value.status===404?'المنتج غير موجود في هذا الفرع':'تعذر تحميل المنتج' }}</h1><p>{{ state.error.value.message }}</p><button class="button button-primary" @click="load">إعادة المحاولة</button></section>
<div v-else-if="product" id="product-detail"><article class="product-detail-layout"><div class="product-detail-image"><div class="product-photo api-product-photo" role="img" :aria-label="product.name"><img v-if="image" :src="image.url" fetchpriority="high" decoding="async" :alt="image.alt_text||product.name"><span v-else class="api-no-image">لا توجد صورة للمنتج</span></div><div v-if="images.length>1" class="product-image-thumbnails"><button v-for="item in images" :key="item.id" :aria-pressed="image?.id===item.id" :aria-label="'عرض '+(item.alt_text||'صورة المنتج')" @click="selectedImage=item.id"><img :src="item.url" :alt="item.alt_text||''"></button></div><span v-if="product.is_featured" class="detail-badge">منتج مميز</span></div>
<div class="product-detail-copy"><span class="section-kicker">{{ product.category?.name }}</span><h1>{{ product.name }}</h1><p v-if="product.short_description" class="detail-description">{{ product.short_description }}</p><div class="detail-price"><strong>{{ money(product.price) }}</strong></div><dl class="nutrition-details"><div><dt>وقت التحضير</dt><dd>{{ product.preparation_time==null?'غير محدد':product.preparation_time+' دقيقة' }}</dd></div><div><dt>التوفر في الفرع</dt><dd>{{ product.available?'متوفر':product.availability_message||'غير متوفر' }}</dd></div></dl><div v-if="product.description" class="detail-section"><h2>تفاصيل المنتج</h2><p>{{ product.description }}</p></div>
<fieldset v-for="group in groups" :key="group.id" class="detail-section product-option-group"><legend>{{ group.name }} · {{ group.is_required?'إلزامي':'اختياري' }}</legend><p>اختر {{ group.min_select }}–{{ group.max_select??'أي عدد' }} {{ group.type==='single'?'(اختيار واحد)':'' }}</p><label v-for="value in group.values" :key="value.id"><input type="checkbox" :checked="(selections[group.id]||[]).includes(value.id)" :disabled="!product.available" @change="toggle(group,value,$event)"><span>{{ value.name }}</span><small>+ {{ money(value.price_modifier) }}</small></label></fieldset>
<p v-if="route.query.cartItem&&!editingItem" role="status" class="catalog-note">تعذر تعديل هذا العنصر. افتح السلة واختره مجددًا.</p><p v-if="optionError" role="alert" class="catalog-note">{{ optionError }}</p><p v-if="groups.length" class="catalog-note">يمكنك مراجعة السعر النهائي في السلة بعد اختيار الإضافات.</p><div class="detail-order"><label class="detail-quantity" for="detail-quantity">الكمية<input id="detail-quantity" v-model.number="quantity" :disabled="!!route.query.cartItem || cart.isMutating" type="number" min="1" max="50" step="1"></label><button class="button button-primary" type="button" :aria-busy="pending" :disabled="pending || !product.available || cart.isLoading || cart.isMutating || (!!route.query.cartItem&&!editingItem)" @click="add"><span v-if="pending" class="cart-add-spinner" aria-hidden="true"></span><SiteIcon v-else name="shopping-bag"/>{{ pending?(editingItem?'جارٍ حفظ الخيارات…':'جارٍ الإضافة…'):editingItem?'حفظ الخيارات':product.available?'إضافة للسلة':'غير متوفر في الفرع' }}</button></div><button class="text-button" type="button" @click="openCart">مراجعة السلة <SiteIcon name="arrow-left"/></button></div></article></div></main></template>
<style scoped>
.product-detail-layout{grid-template-columns:repeat(2,minmax(0,1fr))}
.product-detail-image,.product-detail-copy{min-width:0}
.product-detail-image .product-photo{position:relative;width:100%;min-height:0;aspect-ratio:4/3;overflow:hidden;border-radius:10px}
.product-detail-image .product-photo>img{position:absolute;inset:0;display:block;width:100%;height:100%;object-fit:contain}
.product-image-thumbnails{display:flex;gap:10px;max-width:100%;overflow-x:auto;padding:4px 2px 10px;margin-top:10px;overscroll-behavior-inline:contain}
.product-image-thumbnails>button{flex:0 0 64px;width:64px;height:64px;padding:3px;border:2px solid var(--border);border-radius:8px;background:var(--cream);overflow:hidden;cursor:pointer}
.product-image-thumbnails>button[aria-pressed="true"]{border-color:var(--primary)}
.product-image-thumbnails>button:focus-visible{outline:2px solid var(--primary);outline-offset:1px}
.product-image-thumbnails img{display:block;width:100%;height:100%;object-fit:cover;border-radius:4px}
@media(max-width:699px){.product-detail-layout{grid-template-columns:minmax(0,1fr)}.product-image-thumbnails>button{flex-basis:56px;width:56px;height:56px}}
</style>
