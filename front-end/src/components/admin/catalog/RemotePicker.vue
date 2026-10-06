<script setup>
import { ref, watch, onBeforeUnmount } from 'vue'
import { useApiState } from '../../../composables/useApiState'
import ApiFeedback from './ApiFeedback.vue'
import ApiPagination from './ApiPagination.vue'
const props=defineProps({api:Object,modelValue:String,label:String,exclude:{type:Array,default:()=>[]},multiple:Boolean,disabled:Boolean})
const emit=defineEmits(['update:modelValue','select'])
const state=useApiState(),search=ref(''),page=ref(1)
let timer
function load(){state.run(signal=>props.api.list({search:search.value,page:page.value,per_page:20},signal))}
watch(search,()=>{clearTimeout(timer);state.clear();timer=setTimeout(()=>{page.value=1;load()},300)})
watch(page,load)
load()
onBeforeUnmount(()=>clearTimeout(timer))
</script>
<template><div class="remote-picker"><label class="field">{{ label }}<input v-model="search" :disabled="disabled" type="search" maxlength="150" placeholder="بحث في الخادم…"></label><ApiFeedback variant="list" :loading="state.loading.value" :error="state.error.value" :empty="!!state.data.value&&!state.data.value.data.length" @retry="load"/><label v-for="item in state.data.value?.data || []" :key="item.id" class="check"><input v-if="multiple" type="checkbox" :disabled="disabled" :checked="exclude.includes(item.id)" @change="emit('select',item,$event.target.checked)"><input v-else type="radio" :checked="modelValue===item.id" :disabled="disabled||exclude.includes(item.id)" @change="emit('update:modelValue',item.id)">{{ item.name }}</label><ApiPagination :meta="state.data.value?.meta" :links="state.data.value?.links" :busy="state.loading.value" @page="page=$event"/></div></template>
