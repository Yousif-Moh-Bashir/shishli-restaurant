<script setup>
import { ref, watch, nextTick, onBeforeUnmount, useId } from 'vue'
import AdminIcon from './AdminIcon.vue'
const props = defineProps({ open: Boolean, title: String, busy: Boolean, beforeClose: Function, size:{type:String,default:'medium'} })
const emit = defineEmits(['update:open', 'submit'])
const dialog = ref(null)
const id=useId()
function canClose(){return !props.busy&&(!props.beforeClose||props.beforeClose())}
function close(){if(canClose())dialog.value.close()}
watch(() => props.open, async open => {
  await nextTick()
  if (open && props.open && dialog.value && !dialog.value.open) dialog.value.showModal()
  else if (!props.open && dialog.value?.open) dialog.value.close()
}, { immediate: true, flush: 'post' })
onBeforeUnmount(() => dialog.value?.close())
</script>
<template>
  <dialog :id="'editor-'+id" class="admin-editor-dialog" :class="'admin-dialog-'+size" ref="dialog" :aria-labelledby="'editor-title-'+id" @cancel="!canClose() && $event.preventDefault()" @close="emit('update:open', false)"><div class="dialog-head"><h2 :id="'editor-title-'+id">{{ title }}</h2><button type="button" class="icon-button" aria-label="إغلاق" :disabled="busy" @click="close"><AdminIcon name="x" /></button></div><form class="editor-form" @submit.prevent="emit('submit')"><slot /></form></dialog>
</template>
