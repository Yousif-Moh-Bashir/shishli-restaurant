<script setup>
import {watch,computed} from 'vue'
import {RouterLink} from 'vue-router'
import {useBranchStore} from '../../stores/branch'
import {useApiState} from '../../composables/useApiState'
import {businessHoursApi} from '../../api/businessHours.api'
import {weekdayLabels} from '../../constants/settings'
import AppSkeleton from '../common/AppSkeleton.vue'
const branches=useBranchStore(),state=useApiState()
function load(){if(branches.selectedUuid)return state.run(signal=>businessHoursApi.public(branches.selectedUuid,signal));state.clear()}
watch(()=>branches.selectedUuid,load,{immediate:true})
function time(value){const [hour,minute]=String(value).slice(0,5).split(':').map(Number);return `${hour%12||12}:${String(minute).padStart(2,'0')} ${hour<12?'صباحًا':'مساءً'}`}
const groups=computed(()=>{
 const groups=new Map()
 for(const day of state.data.value?.days||[]){
  const periods=[...new Map((day.periods||[]).map(period=>[`${period.opens_at.slice(0,5)}-${period.closes_at.slice(0,5)}`,period])).values()].sort((a,b)=>a.opens_at.localeCompare(b.opens_at))
  const minutes=value=>{const [hour,minute]=value.slice(0,5).split(':').map(Number);return hour*60+minute}
  const ranges=[]
  for(const period of periods){const start=minutes(period.opens_at);let end=minutes(period.closes_at);if(end<=start)end+=1440;const previous=ranges.at(-1);if(previous&&start<=previous.end)previous.end=Math.max(previous.end,end);else ranges.push({start,end})}
  const clock=value=>`${String(Math.floor(value%1440/60)).padStart(2,'0')}:${String(value%60).padStart(2,'0')}`
  const text=!ranges.length?'مغلق':ranges.some(range=>range.end-range.start>=1440)?'على مدار 24 ساعة':ranges.map(range=>`${time(clock(range.start))} — ${time(clock(range.end))}${range.end>=1440?' (اليوم التالي)':''}`).join('، ')
  const group=groups.get(text)||{text,days:[]};group.days.push(weekdayLabels[day.day_of_week]);groups.set(text,group)
 }
 return [...groups.values()].map(group=>({...group,label:group.days.length===7?'كل أيام الأسبوع':group.days.join('، ')}))
})
</script>
<template><div class="public-business-hours"><AppSkeleton v-if="state.loading.value" variant="inline" label="جارٍ تحميل ساعات العمل…"/><p v-else-if="state.error.value" role="status">ساعات الفرع غير متاحة الآن. <button class="text-button" @click="load">إعادة المحاولة</button></p><template v-else-if="state.data.value"><div v-for="group in groups.slice(0,2)" :key="group.label" class="hours-group"><span>{{ group.label }}</span><p>{{ group.text }}</p></div><details v-if="groups.length>2"><summary>بقية أيام الأسبوع</summary><div v-for="group in groups.slice(2)" :key="group.label" class="hours-group"><span>{{ group.label }}</span><p>{{ group.text }}</p></div></details><span class="hours-note">{{ branches.selectedBranch?.name }} · {{ state.data.value.timezone }}</span></template><p v-else>ساعات العمل حسب الفرع <RouterLink class="footer-link" to="/menu">اختر الفرع</RouterLink></p></div></template>
