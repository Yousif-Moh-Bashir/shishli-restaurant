<script setup>
import {ref,computed,inject} from 'vue'
import {useAuthStore} from '../../../stores/auth'
import {useApiState,useApiMutation} from '../../../composables/useApiState'
import {useUnsavedForm} from '../../../composables/useUnsavedForm'
import {businessHoursApi} from '../../../api/businessHours.api'
import {weekdayLabels} from '../../../constants/settings'
import AdminSectionHeading from '../AdminSectionHeading.vue'
import AdminModal from '../AdminModal.vue'
import ApiFeedback from '../catalog/ApiFeedback.vue'
const props=defineProps({branch:{type:String,required:true}}),emit=defineEmits(['updated','dirty','busy'])
const auth=useAuthStore(),state=useApiState(),mutation=useApiMutation(),{notify}=inject('admin')
const hours=ref([]),baseline=ref('[]'),confirmation=ref(false),saving=ref(false)
const weeklyPeriods=ref([{opens_at:'',closes_at:''}])
function applyWeeklyHours(){
  if(saving.value||!auth.can('branches.hours.update')||!weeklyPeriods.value.length)return
  if(weeklyPeriods.value.some(period=>!/^\d{2}:\d{2}$/.test(period.opens_at)||!/^\d{2}:\d{2}$/.test(period.closes_at)))return
  hours.value=Object.keys(weekdayLabels).flatMap(day=>weeklyPeriods.value.map(period=>({day_of_week:Number(day),opens_at:period.opens_at,closes_at:period.closes_at})))
  mutation.error.value=null
  notify('تم تطبيق الدوام على الأيام السبعة. راجع الجدول ثم اضغط حفظ الأسبوع.')
}
const dirty=computed(()=>JSON.stringify(hours.value)!==baseline.value)
useUnsavedForm(dirty,saving,emit)
const toHours=result=>result.days.flatMap(day=>day.periods.map(period=>({day_of_week:day.day_of_week,...period})))
const canonical=rows=>JSON.stringify(rows.map(row=>({...row,day_of_week:Number(row.day_of_week)})).sort((a,b)=>a.day_of_week-b.day_of_week||a.opens_at.localeCompare(b.opens_at)))
function applyResult(result){state.data.value=result;hours.value=toHours(result);baseline.value=JSON.stringify(hours.value);state.error.value=null;mutation.error.value=null}
async function load(){const result=await state.run(signal=>businessHoursApi.list(props.branch,signal));if(result)applyResult(result)}
function reset(){hours.value=JSON.parse(baseline.value);mutation.error.value=null}
function requestSave(){if(!dirty.value||saving.value)return;mutation.error.value=null;confirmation.value=true}
function saved(result){applyResult(result);confirmation.value=false;notify('تم حفظ ساعات الفرع.');emit('updated')}
async function save(){
  if(saving.value)return
  saving.value=true
  const submitted=hours.value.map(row=>({...row}))
  try{
    const result=await mutation.run(()=>businessHoursApi.replace(props.branch,submitted))
    if(result.ok){saved(result.data);return}
    if(mutation.error.value?.status===422){
      const errors=mutation.error.value.errors||{}
      mutation.error.value={...mutation.error.value,message:'راجع الفترات الموضحة أدناه ثم أعد الحفظ.',errors:Object.fromEntries(Object.entries(errors).map(([key,messages])=>{
        const match=key.match(/^hours\.(\d+)(?:\.|$)/),row=match&&submitted[Number(match[1])]
        const label=row?`${weekdayLabels[row.day_of_week]} (${row.opens_at||'وقت فتح غير محدد'}–${row.closes_at||'وقت إغلاق غير محدد'})`:''
        return [key,messages.map(message=>label?`${label}: ${message}`:message)]
      }))}
    }
    if(mutation.error.value?.status===0){
      // A lost response does not mean the write failed. Read back before allowing another write.
      try{
        const current=await businessHoursApi.list(props.branch)
        if(canonical(toHours(current))===canonical(submitted)){saved(current);return}
      }catch{/* Keep the draft and the original error available for an explicit retry. */}
      mutation.error.value={...mutation.error.value,message:'تعذر التأكد من حفظ الأسبوع. احتفظنا بتعديلاتك؛ تحقق من الاتصال ثم حاول مجددًا.'}
    }
  }finally{saving.value=false}
}
const errorsFor=index=>Object.entries(mutation.error.value?.errors||{}).filter(([key])=>key===`hours.${index}`||key.startsWith(`hours.${index}.`)).flatMap(([,messages])=>messages)
void load()
</script>
<template><section class="panel business-hours-panel"><AdminSectionHeading icon="clock">ساعات عمل الفرع</AdminSectionHeading><ApiFeedback :loading="state.loading.value" :error="state.error.value" @retry="load"/><template v-if="state.data.value"><p class="form-note">توقيت المطعم: <bdi>{{ state.data.value.timezone }}</bdi>. اليوم بلا فترات عمل مغلق. يمكن إضافة أكثر من فترة؛ الإغلاق قبل الفتح يعني امتدادًا إلى اليوم التالي.</p><form v-if="auth.can('branches.hours.update')" class="weekly-hours-quick" @submit.prevent="applyWeeklyHours">
  <h3>دوام موحّد لكل أيام الأسبوع</h3>
  <p>حدد الفترات مرة واحدة لتطبيقها على الأيام السبعة، ثم عدّل أي يوم من الجدول أدناه.</p>
  <fieldset class="settings-fields" :disabled="saving">
    <div v-for="(period,index) in weeklyPeriods" :key="index" class="hours-period">
      <label class="field">وقت الفتح<input v-model="period.opens_at" type="time" required :aria-label="`وقت فتح الفترة ${index+1} لكل الأيام`"></label>
      <label class="field">وقت الإغلاق<input v-model="period.closes_at" type="time" required :aria-label="`وقت إغلاق الفترة ${index+1} لكل الأيام`"></label>
      <button v-if="weeklyPeriods.length>1" class="row-button danger" type="button" @click="weeklyPeriods.splice(index,1)">حذف الفترة</button>
      <small v-if="period.opens_at&&period.closes_at&&period.closes_at<period.opens_at">ينتهي الدوام صباح اليوم التالي.</small>
    </div>
    <div class="weekly-hours-quick-actions">
      <button class="row-button" type="button" :disabled="weeklyPeriods.length>=14" @click="weeklyPeriods.push({opens_at:'',closes_at:''})">إضافة فترة أخرى</button>
      <button class="button secondary" type="submit">تطبيق على كل الأيام</button>
    </div>
  </fieldset>
  <small>التطبيق يستبدل الفترات المعروضة لكل الأيام. لن تُحفظ التغييرات إلا عند الضغط على «حفظ الأسبوع».</small>
</form><form @submit.prevent="requestSave"><fieldset class="settings-fields" :disabled="saving||!auth.can('branches.hours.update')"><div class="weekly-hours"><article v-for="(label,day) in weekdayLabels" :key="day" class="hours-day"><h3>{{ label }} <span v-if="!hours.some(row=>row.day_of_week===Number(day))" class="badge off">مغلق</span></h3><template v-for="(row,index) in hours" :key="index"><div v-if="row.day_of_week===Number(day)" class="hours-period"><label class="field">الفتح<input v-model="row.opens_at" type="time" required :name="`hours.${index}.opens_at`"></label><label class="field">الإغلاق<input v-model="row.closes_at" type="time" required :name="`hours.${index}.closes_at`"></label><button type="button" class="row-button danger" @click="hours.splice(index,1)">حذف الفترة</button><small v-if="row.closes_at<row.opens_at">إغلاق في اليوم التالي</small><small v-for="message in errorsFor(index)" :key="message" class="field-error" role="alert">{{ message }}</small></div></template><button v-if="auth.can('branches.hours.update')" class="row-button" type="button" :disabled="hours.length>=100" @click="hours.push({day_of_week:Number(day),opens_at:'',closes_at:''})">إضافة فترة</button></article></div></fieldset><div class="form-actions"><button v-if="auth.can('branches.hours.update')" class="button" :disabled="saving||!dirty">حفظ الأسبوع</button><button class="row-button" type="button" :disabled="saving||!dirty" @click="reset">تراجع</button><button class="row-button" type="button" :disabled="saving||dirty" @click="load">تحديث</button></div><ApiFeedback v-if="!confirmation" error-title="تعذر حفظ ساعات العمل" :error="mutation.error.value" @retry="requestSave"/></form></template><AdminModal :open="confirmation" title="تأكيد حفظ ساعات العمل" :busy="saving" @update:open="confirmation=$event" @submit="save"><div class="hours-confirmation"><p class="hours-confirmation-note">سيُحفظ جدول الأسبوع كاملًا. الأيام بلا فترات عمل ستكون مغلقة ولن تستقبل طلبات.</p><ApiFeedback error-title="تعذر حفظ ساعات العمل" :error="mutation.error.value" :retry-disabled="saving" @retry="save"/><div class="hours-confirmation-actions"><button class="button" type="submit" :disabled="saving">{{ saving?'جارٍ الحفظ والتحقق…':'تأكيد حفظ الأسبوع' }}</button><button class="row-button" type="button" :disabled="saving" @click="confirmation=false">العودة للتعديل</button></div></div></AdminModal></section></template>
