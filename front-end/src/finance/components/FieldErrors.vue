<script setup>
defineProps({ errors: { type: Object, default: () => ({}) } })
const labels = { branch_uuid: 'الفرع', date: 'التاريخ', amount: 'المبلغ', notes: 'الملاحظات', lines: 'مصادر الإيراد', source_uuid: 'مصدر الإيراد', version: 'إصدار السجل', reason: 'سبب التعديل', description: 'الوصف', payment_method: 'طريقة الدفع', attachment: 'المرفق', category_uuid: 'التصنيف', supplier_uuid: 'المورد', invoice_number: 'رقم الفاتورة', name: 'الاسم', is_active: 'الحالة', phone: 'الهاتف', tax_number: 'الرقم الضريبي', email: 'البريد الإلكتروني', password: 'كلمة المرور', role: 'الدور المالي', branch_uuids: 'الفروع المسموحة' }
const label = field => field.startsWith('lines.') ? `${labels[field.split('.').at(-1)] || 'المصدر'} (${Number(field.split('.')[1]) + 1})` : labels[field.split('.')[0]] || 'البيانات'
</script>
<template><div v-if="Object.keys(errors).length" class="notice danger" role="alert"><ul><li v-for="(messages, field) in errors" :key="field"><strong>{{ label(field) }}:</strong> {{ Array.isArray(messages) ? messages.join('، ') : messages }}</li></ul></div></template>
