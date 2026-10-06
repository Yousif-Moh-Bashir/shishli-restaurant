<script setup>
import {useId,watchEffect,computed} from 'vue'
const props=defineProps({fields:Array,form:Object,errors:{type:Object,default:()=>({})},disabled:Boolean,sections:Array})
const groups=computed(()=>{
 if(!props.sections?.length)return [{title:'',fields:props.fields}]
 const assigned=new Set(props.sections.flatMap(section=>section.keys))
 return [...props.sections.map(section=>({title:section.title,fields:props.fields.filter(field=>section.keys.includes(field.key))})),{title:'إعدادات إضافية',fields:props.fields.filter(field=>!assigned.has(field.key))}].filter(group=>group.fields.length)
})
const id=useId()
const fileInputs=new Map()
function fileRef(key,element){if(element)fileInputs.set(key,element);else fileInputs.delete(key)}
// Native file inputs retain a previous selection when a modal is reused.
// Reset the element together with the draft so choosing the same file emits change.
watchEffect(()=>{for(const field of props.fields.filter(f=>f.type==='file')){const element=fileInputs.get(field.key);if(!props.form[field.key]&&element)element.value=''}},{flush:'post'})
const errorsFor=key=>Object.entries(props.errors).filter(([name])=>name===key||name.startsWith(key+'.')).flatMap(([,value])=>value)
</script>
<template><div class="domain-form-fields"><component :is="group.title?'fieldset':'div'" v-for="(group,index) in groups" :key="index" :class="{'form-section':group.title}"><legend v-if="group.title">{{ group.title }}</legend><div class="form-grid"><div v-for="field in group.fields" :key="field.key" :class="['field',{'field-wide':['textarea','multi','items'].includes(field.type),'field-toggle':field.type==='checkbox'}]">
 <label :for="id+'-'+field.key">{{ field.label }}<span v-if="field.required" class="required-marker" aria-hidden="true">*</span></label>
 <textarea v-if="field.type==='textarea'" :id="id+'-'+field.key" v-model="form[field.key]" :required="field.required" :maxlength="field.max" :minlength="field.minlength" :disabled="disabled" :aria-invalid="errorsFor(field.key).length?true:undefined" :aria-describedby="errorsFor(field.key).length?id+'-error-'+field.key:undefined" rows="4"/>
 <select v-else-if="field.type==='select'" :id="id+'-'+field.key" v-model="form[field.key]" :required="field.required" :disabled="disabled" :aria-invalid="errorsFor(field.key).length?true:undefined" :aria-describedby="errorsFor(field.key).length?id+'-error-'+field.key:undefined"><option v-for="option in field.options" :key="option.value" :value="option.value">{{ option.label }}</option></select>
 <input v-else-if="field.type==='checkbox'" :id="id+'-'+field.key" v-model="form[field.key]" type="checkbox" :disabled="disabled" :aria-invalid="errorsFor(field.key).length?true:undefined" :aria-describedby="errorsFor(field.key).length?id+'-error-'+field.key:undefined">
 <input v-else-if="field.type==='file'" :ref="element=>fileRef(field.key,element)" :id="id+'-'+field.key" type="file" accept="image/jpeg,image/png,image/webp" :required="field.required" :disabled="disabled" :aria-invalid="errorsFor(field.key).length?true:undefined" :aria-describedby="errorsFor(field.key).length?id+'-error-'+field.key:undefined" @change="form[field.key]=$event.target.files[0]||null">
 <div v-else-if="field.type==='multi'" :id="id+'-'+field.key" class="domain-checks"><label v-for="option in field.options" :key="option.value" class="check"><input v-model="form[field.key]" type="checkbox" :value="option.value" :disabled="disabled||option.disabled">{{ option.label }}</label></div>
 <input v-else :id="id+'-'+field.key" v-model="form[field.key]" :type="field.type||'text'" :required="field.required" :maxlength="field.max" :min="field.min" :max="field.type==='number'?field.max:undefined" :step="field.step" :pattern="field.pattern" :minlength="field.minlength" :disabled="disabled" :aria-invalid="errorsFor(field.key).length?true:undefined" :aria-describedby="errorsFor(field.key).length?id+'-error-'+field.key:undefined" :autocomplete="field.type==='password'?'new-password':undefined" :dir="field.dir||(['email','url','password','tel'].includes(field.type)?'ltr':undefined)">
 <small v-if="field.help" class="muted">{{ field.help }}</small><small v-if="errorsFor(field.key).length" :id="id+'-error-'+field.key" role="alert" class="field-error">{{ errorsFor(field.key).join(' ') }}</small>
 </div></div></component></div></template>
