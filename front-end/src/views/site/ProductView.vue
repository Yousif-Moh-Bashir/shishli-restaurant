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
const route=useRoute(),branch=useBranchStore(),state=useApiState(),cart=useCartStore(),quantity=ref(1),selections=ref({}),selectedImage=ref(null),optionError=ref('')
const {notifyCartResult,openCart,toast}=inject('site')
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
    document.title=result.name+' | مطعم شيشلي'
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
  optionError.value=''
  for(const group of groups.value){const count=(selections.value[group.id]||[]).length
    if((group.is_required&&count===0)||count<group.min_select||(group.max_select!=null&&count>group.max_select)){optionError.value='راجع اختيارات '+group.name+'؛ الحد الأدنى '+group.min_select+' والحد الأقصى '+(group.max_select??'بلا حد')+'.';return}}
  if(!Number.isInteger(quantity.value)||quantity.value<1||quantity.value>50){toast('اختر كمية صحيحة من 1 إلى 50.');return}
  if(product.value?.available===true){
    const itemToEdit=editingItem.value
    const result=itemToEdit?await cart.updateOptions(itemToEdit.id,selectedOptions.value):await cart.addItem(menuProduct(product.value,branch.selectedUuid),quantity.value,selectedOptions.value)
    if(itemToEdit){toast(result.ok?'تم تحديث خيارات السلة.':result.message)}else notifyCartResult(result,product.value.name)
    if(!result.ok)optionError.value=Object.values(cart.error?.errors||{}).flat().join(' ')||result.message
  }
}
</script>
<template><main id="main" class="container product-main"><RouterLink class="auth-back" to="/menu">→ العودة للمنيو</RouterLink><BranchSelector/>
<p v-if="!branch.selectedBranch&&!branch.isLoading&&!branch.error" class="empty-search">اختر الفرع لعرض تفاصيل المنتج.</p><AppSkeleton v-if="state.loading.value" variant="detail" label="جارٍ تحميل المنتج…"/>
<section v-else-if="state.error.value" class="checkout-empty" role="alert"><h1>{{ state.error.value.status===404?'المنتج غير موجود في هذا الفرع':'تعذر تحميل المنتج' }}</h1><p>{{ state.error.value.message }}</p><button class="button button-primary" @click="load">إعادة المحاولة</button></section>
<div v-else-if="product" id="product-detail"><article class="product-detail-layout"><div class="product-detail-image"><div class="product-photo api-product-photo" role="img" :aria-label="product.name"><img v-if="image" :src="image.url" :alt="image.alt_text||product.name"><span v-else class="api-no-image">لا توجد صورة للمنتج</span></div><div v-if="images.length>1" class="product-image-thumbnails"><button v-for="item in images" :key="item.id" :aria-pressed="image?.id===item.id" :aria-label="'عرض '+(item.alt_text||'صورة المنتج')" @click="selectedImage=item.id"><img :src="item.url" :alt="item.alt_text||''"></button></div><span v-if="product.is_featured" class="detail-badge">منتج مميز</span></div>
<div class="product-detail-copy"><span class="section-kicker">{{ product.category?.name }}</span><h1>{{ product.name }}</h1><p class="detail-description">{{ product.short_description || product.description }}</p><div class="detail-price"><strong>{{ money(product.price) }}</strong></div><dl class="nutrition-details"><div><dt>وقت التحضير</dt><dd>{{ product.preparation_time==null?'غير محدد':product.preparation_time+' دقيقة' }}</dd></div><div><dt>التوفر في الفرع</dt><dd>{{ product.available?'متوفر':product.availability_message||'غير متوفر' }}</dd></div></dl><div v-if="product.description" class="detail-section"><h2>تفاصيل المنتج</h2><p>{{ product.description }}</p></div>
<fieldset v-for="group in groups" :key="group.id" class="detail-section product-option-group"><legend>{{ group.name }} · {{ group.is_required?'إلزامي':'اختياري' }}</legend><p>اختر {{ group.min_select }}–{{ group.max_select??'أي عدد' }} {{ group.type==='single'?'(اختيار واحد)':'' }}</p><label v-for="value in group.values" :key="value.id"><input type="checkbox" :checked="(selections[group.id]||[]).includes(value.id)" :disabled="!product.available" @change="toggle(group,value,$event)"><span>{{ value.name }}</span><small>+ {{ money(value.price_modifier) }}</small></label></fieldset>
<p v-if="route.query.cartItem&&!editingItem" role="status" class="catalog-note">هذا السطر لم يعد موجودًا أو اندمج مع سطر آخر. راجع السلة لاختيار السطر الحالي.</p><p v-if="optionError" role="alert" class="catalog-note">{{ optionError }}</p><p v-if="groups.length" class="catalog-note">أسعار الإضافات موضحة؛ السعر النهائي يأتي من الخادم بعد الإضافة أو تعديل الخيارات.</p><div class="detail-order"><label class="detail-quantity" for="detail-quantity">الكمية<input id="detail-quantity" v-model.number="quantity" :disabled="!!route.query.cartItem || cart.isMutating" type="number" min="1" max="50" step="1"></label><button class="button button-primary" type="button" :disabled="!product.available || cart.isLoading || cart.isMutating || (!!route.query.cartItem&&!editingItem)" @click="add"><SiteIcon name="shopping-bag"/>{{ cart.isMutating?'جارٍ تحديث السلة…':editingItem?'حفظ الخيارات':product.available?'إضافة للسلة':'غير متوفر في الفرع' }}</button></div><button class="text-button" type="button" @click="openCart">مراجعة السلة <SiteIcon name="arrow-left"/></button></div></article></div></main></template>



