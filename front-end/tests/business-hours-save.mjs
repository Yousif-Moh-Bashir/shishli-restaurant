import assert from 'node:assert/strict'
import {readFileSync} from 'node:fs'
import vm from 'node:vm'

const source=readFileSync(new URL('../src/components/admin/operations/BusinessHoursPanel.vue',import.meta.url),'utf8').split('<script setup>')[1].split('</script>')[0].replace(/^import .*$/gm,'').replace('void load()','')
const row={day_of_week:1,opens_at:'09:00',closes_at:'22:00'}
const response={timezone:'Asia/Riyadh',days:[{day_of_week:1,periods:[{opens_at:'09:00',closes_at:'22:00'}]}]}
async function scenario(mode){
  let writes=0,reads=0,notifications=0
  const error={value:null},state={data:{value:null},error:{value:null}}
  const context=vm.createContext({
    ref:value=>({value}),computed:fn=>({get value(){return fn()}}),defineProps:()=>({branch:'branch'}),defineEmits:()=>()=>{},useUnsavedForm:()=>{},useAuthStore:()=>({can:()=>true}),inject:()=>({notify:()=>notifications++}),useApiState:()=>state,
    weekdayLabels:{1:'الاثنين',2:'الثلاثاء',3:'الأربعاء',4:'الخميس',5:'الجمعة',6:'السبت',7:'الأحد'},
    useApiMutation:()=>({error,run:async request=>{try{return {ok:true,data:await request()}}catch{return error.value={status:mode==='validation'?422:0,errors:mode==='validation'?{'hours.0.opens_at':['أدخل وقت الفتح.']}:{}}, {ok:false}}}}),
    businessHoursApi:{replace:async()=>{writes++;if(mode!=='success')throw Error('lost response');return response},list:async()=>{reads++;if(mode==='offline')throw Error('offline');return response}},
  })
  vm.runInContext(source+'\nhours.value=[{day_of_week:1,opens_at:"09:00",closes_at:"22:00"}];confirmation.value=true;globalThis.runSave=save;globalThis.inspect=()=>({hours:hours.value,dirty:dirty.value,confirmation:confirmation.value,saving:saving.value});',context)
  await context.runSave()
  const actual=context.inspect()
  assert.equal(writes,1)
  assert.equal(actual.saving,false)
  if(mode==='success'||mode==='lost'){
    assert.equal(actual.dirty,false);assert.equal(actual.confirmation,false);assert.equal(notifications,1);assert.equal(error.value,null)
  }else{
    assert.equal(JSON.stringify(actual.hours),JSON.stringify([row]));assert.equal(actual.dirty,true);assert.equal(actual.confirmation,true);assert.equal(notifications,0)
  }
  assert.equal(reads,mode==='lost'||mode==='offline'?1:0)
  if(mode==='validation')assert.match(error.value.errors['hours.0.opens_at'][0],/^الاثنين \(09:00–22:00\): أدخل وقت الفتح\.$/)
  vm.runInContext('weeklyPeriods.value=[{opens_at:"12:00",closes_at:"02:00"}];applyWeeklyHours();',context)
  let week=context.inspect().hours
  assert.equal(week.length,7)
  assert.deepEqual(Array.from(week,row=>row.day_of_week),[1,2,3,4,5,6,7])
  assert.ok(week.every(row=>row.opens_at==='12:00'&&row.closes_at==='02:00'))
  vm.runInContext('hours.value[0].opens_at="13:00";',context)
  assert.equal(context.inspect().hours[1].opens_at,'12:00')
  vm.runInContext('weeklyPeriods.value=[{opens_at:"09:00",closes_at:"12:00"},{opens_at:"17:00",closes_at:"02:00"}];applyWeeklyHours();',context)
  assert.equal(context.inspect().hours.length,14)
  vm.runInContext('weeklyPeriods.value=[{opens_at:"",closes_at:"02:00"}];applyWeeklyHours();',context)
  assert.equal(context.inspect().hours.length,14)
  assert.equal(writes,1,'Applying to the week only changes the draft')
}
for(const mode of ['success','lost','offline','validation'])await scenario(mode)
console.log('Business hours: save/reconciliation/errors, seven-day overnight application, multiple periods, independent daily edits and draft-only application passed.')
