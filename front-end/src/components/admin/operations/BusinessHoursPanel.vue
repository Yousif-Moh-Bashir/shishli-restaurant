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
const dirty=computed(()=>JSON.stringify(hours.value)!==baseline.value)
useUnsavedForm(dirty,saving,emit)
async function load(){const result=await state.run(signal=>businessHoursApi.list(props.branch,signal));if(result){hours.value=result.days.flatMap(day=>day.periods.map(period=>({day_of_week:day.day_of_week,...period})));baseline.value=JSON.stringify(hours.value)}}
function reset(){hours.value=JSON.parse(baseline.value);mutation.error.value=null}
function requestSave(){if(!dirty.value||saving.value)return;confirmation.value=true}
async function save(){if(saving.value)return;saving.value=true;try{const result=await mutation.run(()=>businessHoursApi.replace(props.branch,hours.value));if(result.ok){confirmation.value=false;notify('تم حفظ ساعات الفرع.');await load();emit('updated')}}finally{saving.value=false}}
const errorsFor=index=>Object.entries(mutation.error.value?.errors||{}).filter(([key])=>key===`hours.${index}`||key.startsWith(`hours.${index}.`)).flatMap(([,messages])=>messages)
void load()
</script>
<template><section class="panel business-hours-panel"><AdminSectionHeading icon="clock">ساعات عمل الفرع</AdminSectionHeading><ApiFeedback :loading="state.loading.value" :error="state.error.value" @retry="load"/><template v-if="state.data.value"><p class="form-note">توقيت المطعم: <bdi>{{ state.data.value.timezone }}</bdi>. اليوم بلا فترات عمل مغلق. يمكن إضافة أكثر من فترة؛ الإغلاق قبل الفتح يعني امتدادًا إلى اليوم التالي.</p><form @submit.prevent="requestSave"><fieldset class="settings-fields" :disabled="saving||!auth.can('branches.hours.update')"><div class="weekly-hours"><article v-for="(label,day) in weekdayLabels" :key="day" class="hours-day"><h3>{{ label }} <span v-if="!hours.some(row=>row.day_of_week===Number(day))" class="badge off">مغلق</span></h3><template v-for="(row,index) in hours" :key="index"><div v-if="row.day_of_week===Number(day)" class="hours-period"><label class="field">الفتح<input v-model="row.opens_at" type="time" required :name="`hours.${index}.opens_at`"></label><label class="field">الإغلاق<input v-model="row.closes_at" type="time" required :name="`hours.${index}.closes_at`"></label><button type="button" class="row-button danger" @click="hours.splice(index,1)">حذف الفترة</button><small v-if="row.closes_at<row.opens_at">إغلاق في اليوم التالي</small><small v-for="message in errorsFor(index)" :key="message" class="field-error" role="alert">{{ message }}</small></div></template><button v-if="auth.can('branches.hours.update')" class="row-button" type="button" :disabled="hours.length>=100" @click="hours.push({day_of_week:Number(day),opens_at:'',closes_at:''})">إضافة فترة</button></article></div></fieldset><div class="form-actions"><button v-if="auth.can('branches.hours.update')" class="button" :disabled="saving||!dirty">حفظ الأسبوع</button><button class="row-button" type="button" :disabled="saving||!dirty" @click="reset">تراجع</button><button class="row-button" type="button" :disabled="saving||dirty" @click="load">تحديث</button></div><ApiFeedback :error="mutation.error.value" @retry="mutation.error.value=null"/></form></template><AdminModal :open="confirmation" title="تأكيد حفظ ساعات العمل" :busy="saving" @update:open="confirmation=$event" @submit="save"><p>سيستبدل جدول الأسبوع الحالي بالكامل. الأيام التي لا تحتوي فترات تصبح مغلقة، وقد يتوقف استقبال الطلبات.</p><button class="button" :disabled="saving">{{ saving?'جارٍ الحفظ…':'تأكيد حفظ الأسبوع' }}</button><ApiFeedback :error="mutation.error.value" @retry="mutation.error.value=null"/></AdminModal></section></template>
