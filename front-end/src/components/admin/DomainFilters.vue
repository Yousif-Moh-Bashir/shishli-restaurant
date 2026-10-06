<script setup>
import DomainFields from './DomainFields.vue'
import RemotePicker from './catalog/RemotePicker.vue'
import AdminFilterSummary from './AdminFilterSummary.vue'
import {computed} from 'vue'
const props=defineProps({form:Object,fields:Array,pickers:{type:Array,default:()=>[]},busy:Boolean})
const emit=defineEmits(['clear'])
const defaults={...props.form}
const keys=computed(()=>[...props.fields.map(field=>field.key),...props.pickers.map(picker=>picker.key)])
function clear(){for(const key of keys.value)props.form[key]=defaults[key]??'';emit('clear')}
</script>
<template><details class="admin-filter-extras"><summary>خيارات البحث والترتيب</summary><div class="editor-grid"><DomainFields :fields="fields" :form="form" :disabled="busy"/><RemotePicker v-for="picker in pickers" :key="picker.key" v-model="form[picker.key]" :api="picker.api" :label="picker.label" :disabled="busy"/><button v-for="picker in pickers.filter(p=>form[p.key])" :key="picker.key" type="button" class="row-button" :disabled="busy" @click="form[picker.key]=''">مسح {{ picker.label }}</button></div></details><AdminFilterSummary :filters="form" :defaults="defaults" :keys="keys" :busy="busy" @clear="clear"/></template>
