<script setup>
import {computed,watch,onMounted,onBeforeUnmount} from 'vue'
import {useBranchStore} from '../../stores/branch'
import {useOperationsStore} from '../../stores/operations'
import {usePublicSettingsStore} from '../../stores/publicSettings'
import AppSkeleton from '../common/AppSkeleton.vue'
const branches=useBranchStore(),operations=useOperationsStore(),settings=usePublicSettingsStore()
const state=computed(()=>operations.status[branches.selectedUuid])
const busy=computed(()=>operations.loading[branches.selectedUuid]||settings.loading)
const error=computed(()=>operations.errors[branches.selectedUuid]||settings.error)
const next=computed(()=>{if(!state.value?.next_opening_at)return '';try{return new Intl.DateTimeFormat('ar',{dateStyle:'medium',timeStyle:'short',timeZone:settings.data?.restaurant?.timezone||'UTC'}).format(new Date(state.value.next_opening_at))}catch{return state.value.next_opening_at}})
function refresh(force=false){return Promise.all([settings.load(force),operations.load(branches.selectedUuid,force)])}
watch(()=>branches.selectedUuid,()=>refresh(),{immediate:true})
function visible(){if(document.visibilityState==='visible')refresh(true)}
onMounted(()=>document.addEventListener('visibilitychange',visible));onBeforeUnmount(()=>document.removeEventListener('visibilitychange',visible))
</script>
<template><span v-if="branches.selectedUuid" class="branch-operation-note" role="status"><template v-if="error">تعذر التحقق من حالة الفرع</template><template v-else-if="state"><span class="operation-dot" :class="{'is-open':state.is_open}" aria-hidden="true"></span>{{ state.is_open?'مفتوح الآن':'مغلق حاليًا' }}<span v-if="state.is_open&&!state.accepts_orders"> · الطلب متوقف مؤقتًا</span></template><template v-else-if="busy">جارٍ التحقق…</template></span></template>
