<script setup>
import {computed} from 'vue'
const props=defineProps({filters:Object,defaults:{type:Object,default:()=>({})},keys:Array,busy:Boolean})
defineEmits(['clear'])
const active=computed(()=>Object.entries(props.filters).filter(([key,value])=>(!props.keys||props.keys.includes(key))&&value!==''&&value!=null&&value!==false&&JSON.stringify(value)!==JSON.stringify(props.defaults[key])))
</script>
<template><div v-if="active.length" class="filter-summary"><span class="filter-chip">{{ active.length }} فلاتر محددة</span><span v-if="filters.search">بحث: {{ filters.search }}</span><button type="button" class="row-button" :disabled="busy" @click="$emit('clear')">مسح الفلاتر</button></div></template>
