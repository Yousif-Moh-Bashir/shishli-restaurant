<script setup>
import AppSkeleton from "../../common/AppSkeleton.vue"
defineProps({ loading: Boolean, error: Object, errorTitle:{type:String,default:'تعذر تحميل البيانات'}, retryDisabled:Boolean, empty: Boolean, variant: {type:String,default:'table'},emptyTitle:{type:String,default:'لا توجد نتائج'},emptyDescription:{type:String,default:'جرّب تعديل البحث أو مسح الفلاتر لعرض المزيد.'} })
defineEmits(['retry'])
</script>
<template><AppSkeleton v-if="loading" :variant="variant" label="جارٍ تحميل البيانات…"/><div v-else-if="error" class="empty admin-feedback-error" role="alert"><strong>{{ errorTitle }}</strong><p>{{ error.status === 404 ? 'لم تعد البيانات المطلوبة موجودة.' : error.message }}</p><ul v-if="Object.keys(error.errors || {}).length"><li v-for="(messages, field) in error.errors" :key="field">{{ messages.join(' ') }}</li></ul><button class="row-button" type="button" :disabled="retryDisabled" @click="$emit('retry')">إعادة المحاولة</button></div><div v-else-if="empty" class="empty admin-empty-state" role="status"><strong>{{ emptyTitle }}</strong><p>{{ emptyDescription }}</p><slot name="empty-action"/></div></template>
