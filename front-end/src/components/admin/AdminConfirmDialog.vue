<script setup>
import AdminModal from './AdminModal.vue'
defineProps({message:String})
defineEmits(['answer'])
</script>
<template><AdminModal :open="!!message" title="تأكيد الإجراء" class="admin-confirm-dialog" @submit="$emit('answer',true)" @update:open="!$event&&$emit('answer',false)"><p>{{ message }}</p><div class="form-actions"><button class="button danger" type="submit">تأكيد</button><button class="row-button" type="button" @click="$emit('answer',false)">إلغاء</button></div></AdminModal></template>
