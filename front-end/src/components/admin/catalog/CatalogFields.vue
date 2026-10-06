<script setup>
import {computed} from 'vue'
const props=defineProps({ fields: Array, form: Object, errors: {type:Object,default:()=>({})}, categories: {type:Array,default:()=>[]}, disabled: Boolean, sections:Array })
const groups=computed(()=>{
 if(!props.sections?.length)return [{title:'',fields:props.fields}]
 const assigned=new Set(props.sections.flatMap(section=>section.keys))
 return [...props.sections.map(section=>({title:section.title,fields:props.fields.filter(field=>section.keys.includes(field.key))})),{title:'إعدادات إضافية',fields:props.fields.filter(field=>!assigned.has(field.key))}].filter(group=>group.fields.length)
})
</script>
<template><div class="catalog-form-fields"><component :is="group.title?'fieldset':'div'" v-for="(group,index) in groups" :key="index" :class="{'form-section':group.title}"><legend v-if="group.title">{{ group.title }}</legend><div class="form-grid"><label v-for="field in group.fields" :key="field.key" :class="field.type === 'checkbox' ? 'check' : ['field',{'field-wide':field.type==='textarea'}]">
<input v-if="field.type==='checkbox'" v-model="form[field.key]" :name="field.key" type="checkbox" :disabled="disabled" :aria-invalid="errors[field.key]?.length?true:undefined"><span class="field-label">{{ field.label }}<span v-if="field.required" class="required-marker" aria-hidden="true">*</span></span>
<template v-if="field.type!=='checkbox'">
<select v-if="field.type==='category'" v-model="form[field.key]" :name="field.key" :required="field.required" :disabled="disabled" :aria-invalid="errors[field.key]?.length?true:undefined"><option value="">{{ field.required ? 'اختر التصنيف' : 'بدون قسم أب' }}</option><option v-for="category in categories" :key="category.id" :value="category.id">{{ category.label || category.name }}</option></select>
<select v-else-if="field.type==='select'" v-model="form[field.key]" :name="field.key" :required="field.required" :disabled="disabled" :aria-invalid="errors[field.key]?.length?true:undefined"><option v-for="[value,label] in field.choices" :key="value" :value="value">{{ label }}</option></select>
<textarea v-else-if="field.type==='textarea'" v-model="form[field.key]" :name="field.key" :maxlength="field.max" :disabled="disabled" :aria-invalid="errors[field.key]?.length?true:undefined" rows="3"></textarea>
<input v-else v-model="form[field.key]" :name="field.key" :type="field.type" :dir="field.dir||(['slug','sku','phone','whatsapp','email','latitude','longitude','center_latitude','center_longitude'].includes(field.key)?'ltr':undefined)" :maxlength="field.type==='text'?field.max:undefined" :min="field.min" :max="field.type==='number'?field.max:undefined" :step="field.step" :pattern="field.pattern" :required="field.required" :disabled="disabled" :aria-invalid="errors[field.key]?.length?true:undefined">
</template><small v-if="errors[field.key]" class="field-error" role="alert">{{ errors[field.key].join(' ') }}</small>
</label></div></component></div></template>
