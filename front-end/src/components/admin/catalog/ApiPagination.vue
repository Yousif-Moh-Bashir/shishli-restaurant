<script setup>
defineProps({ meta: Object, links: Object, busy: Boolean })
defineEmits(['page'])
</script>
<template><nav v-if="meta" class="api-pagination" aria-label="صفحات النتائج"><span role="status">{{ meta.from ?? 0 }}–{{ meta.to ?? 0 }} من {{ meta.total }} · صفحة {{ meta.current_page }} / {{ meta.last_page }}</span><div class="pagination-actions"><button class="row-button" type="button" :disabled="busy || !links?.prev" @click="$emit('page', meta.current_page - 1)">السابق</button><button class="row-button" type="button" :disabled="busy || !links?.next" @click="$emit('page', meta.current_page + 1)">التالي</button></div></nav></template>
