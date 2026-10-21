<script setup>
import { computed } from 'vue'
import { deliveryLabels } from '../../constants/driverDelivery'
const props=defineProps({status:String,deliveryStatus:String,deliveryOnly:Boolean,type:{type:String,default:'delivery'}})
const preparation=[{code:'pending',label:'استلام الطلب'},{code:'confirmed',label:'تأكيد المطعم'},{code:'preparing',label:'تجهيز الطلب'},{code:'ready',label:'جاهز لدى المطعم'}]
const deliveries=[{code:'unassigned',label:'بانتظار المندوب'},{code:'assigned',label:'إسناد للمندوب'},{code:'picked_up',label:'استلام من الفرع'},{code:'on_the_way',label:'في الطريق'},{code:'delivered',label:'تسليم العميل'}]
const preparationIndex=computed(()=>['out_for_delivery','completed'].includes(props.status)?3:preparation.findIndex(step=>step.code===props.status))
const deliveryIndex=computed(()=>props.status==='cancelled'?-1:deliveries.findIndex(step=>step.code===(props.deliveryStatus||'unassigned')))
const note=computed(()=>{
  if(props.status==='cancelled')return 'الطلب ملغي؛ لا توجد خطوات توصيل متاحة.'
  if(props.type!=='delivery')return props.status==='ready'?'الطلب جاهز للاستلام من الفرع.':props.status==='completed'?'استلم العميل الطلب من الفرع.':''
  if(props.deliveryStatus==='failed')return 'تعذر التسليم. تراجع الإدارة السبب وتعيد الإسناد عند الحاجة.'
  if(props.deliveryStatus==='returning')return 'جارٍ إرجاع الطلب للمطعم. تبقى العهدة على المندوب حتى تأكيد الاستلام.'
  if(props.deliveryStatus==='returned')return 'استلم المطعم الطلب وانتهت عهدة التوصيل. لم يُسجل تسليم للعميل؛ تراجع الإدارة الإغلاق والجانب المالي.'
  if(props.deliveryStatus==='released')return 'انتهت عهدة المندوب بإجراء إداري موثق دون تسجيل تسليم للعميل أو إثبات عودة للمطعم.'
  if(props.deliveryStatus==='delivered')return 'تم تسليم الطلب للعميل واكتملت عملية التوصيل.'
  if(props.deliveryStatus==='on_the_way')return 'المندوب في الطريق للعميل. يُؤكَّد التسليم بعد الوصول والتحقق من الدفع.'
  if(props.deliveryStatus==='picked_up')return 'استلم المندوب الطلب من الفرع. الخطوة التالية: بدء التوصيل.'
  if(props.deliveryStatus==='assigned')return props.status==='ready'?'الطلب جاهز ومُسند لمندوب. الخطوة التالية: الاستلام من الفرع.':'تم إسناد الطلب. ينتظر المندوب جاهزيته قبل الاستلام من الفرع.'
  if(props.status==='ready')return 'الطلب جاهز لدى المطعم وينتظر مندوبًا. يظهر لمناديب هذا الفرع في قائمة الطلبات المتاحة.'
  if(props.status==='out_for_delivery')return 'خرج الطلب للتوصيل دون مندوب مسجل. تحتاج الإدارة إلى إسناده للمندوب المسؤول.'
  if(props.status==='completed')return 'اكتمل الطلب.'
  return 'التجهيز لدى المطعم، والتوصيل لدى المندوب. يظهر الطلب الجاهز أو المتوقع تجهيزه خلال 15 دقيقة لمناديب الفرع.'
})
</script>
<template><section class="order-lifecycle" :aria-label="deliveryOnly?'مراحل توصيل الطلب':'تسلسل الطلب والتوصيل'"><div v-if="!deliveryOnly" class="lifecycle-track"><h3>تجهيز الطلب</h3><ol><li v-for="(step,index) in preparation" :key="step.code" :class="{done:index<preparationIndex,current:index===preparationIndex}" :aria-current="index===preparationIndex?'step':undefined"><span class="lifecycle-dot">{{ index<preparationIndex?'✓':index+1 }}</span>{{ step.label }}</li></ol></div><div v-if="type==='delivery'" class="lifecycle-track"><h3>توصيل الطلب <span v-if="deliveryStatus==='failed'" class="lifecycle-exception">{{ deliveryLabels.failed }}</span></h3><ol><li v-for="(step,index) in deliveries" :key="step.code" :class="{done:index<deliveryIndex,current:index===deliveryIndex}" :aria-current="index===deliveryIndex?'step':undefined"><span class="lifecycle-dot">{{ index<deliveryIndex?'✓':index+1 }}</span>{{ step.label }}</li></ol></div><p v-if="note" class="lifecycle-note" :class="{exception:status==='cancelled'||deliveryStatus==='failed'}">{{ note }}</p></section></template>
<style scoped>
.order-lifecycle{padding:16px;margin-block:16px;border:1px solid #e9e1df;border-radius:10px;background:#faf7f2;color:#431520}.lifecycle-track+.lifecycle-track{margin-top:18px}.lifecycle-track h3{margin:0 0 12px;font-size:13px}.lifecycle-track ol{display:flex;flex-wrap:wrap;gap:10px 16px;list-style:none;padding:0;margin:0}.lifecycle-track li{display:flex;align-items:center;gap:7px;font-size:12px;color:#807066;line-height:1.7}.lifecycle-dot{display:grid;place-items:center;width:24px;height:24px;border:1px solid #dfd3c8;border-radius:50%;flex-shrink:0;font-size:11px}.lifecycle-track li.done{color:#28663d}.done .lifecycle-dot{background:#edf7ef;border-color:#c6dfcb}.lifecycle-track li.current{color:#431520;font-weight:700}.current .lifecycle-dot{background:#431520;border-color:#431520;color:white}.lifecycle-note{margin:16px 0 0;padding-top:12px;border-top:1px solid #e9e1df;font-size:12px;line-height:1.8}.lifecycle-note.exception,.lifecycle-exception{color:#9a3333}.lifecycle-exception{margin-inline-start:8px}@media(max-width:480px){.lifecycle-track ol{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.order-lifecycle{padding:12px}}
</style>
