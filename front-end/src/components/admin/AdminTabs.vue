<script setup>
import { useId } from 'vue'
const props=defineProps({modelValue:String,items:Array,label:{type:String,default:'أقسام الصفحة'}})
const emit=defineEmits(['update:modelValue'])
const id=useId()
function keyboard(event,index){
  const direction=document.documentElement.dir==='rtl'?-1:1
  let next
  if(event.key==='ArrowRight')next=(index+direction+props.items.length)%props.items.length
  else if(event.key==='ArrowLeft')next=(index-direction+props.items.length)%props.items.length
  else if(event.key==='Home')next=0
  else if(event.key==='End')next=props.items.length-1
  if(next===undefined)return
  event.preventDefault();emit('update:modelValue',props.items[next].key)
  event.currentTarget.parentElement.querySelectorAll('button')[next]?.focus()
}
</script>
<template><div class="admin-tabs" role="tablist" :aria-label="label"><button v-for="(item,index) in items" :id="id+'-'+item.key" :key="item.key" type="button" role="tab" :aria-selected="modelValue===item.key" :aria-controls="id+'-panel'" :tabindex="modelValue===item.key?0:-1" @click="emit('update:modelValue',item.key)" @keydown="keyboard($event,index)">{{ item.label }}</button></div><div :id="id+'-panel'" role="tabpanel" :aria-labelledby="id+'-'+modelValue" class="admin-tab-panel"><slot/></div></template>
