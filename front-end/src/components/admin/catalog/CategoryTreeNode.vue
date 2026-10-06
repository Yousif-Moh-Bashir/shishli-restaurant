<script setup>
import AdminIcon from '../AdminIcon.vue'
defineOptions({ name: 'CategoryTreeNode' })
defineProps({ node:Object, collapsed:Set, searching:Boolean, busy:Boolean, canEdit:Boolean, canDelete:Boolean })
defineEmits(['toggle','edit','delete'])
</script>
<template>
  <li class="category-tree-node">
    <div class="category-tree-row">
      <button v-if="node.children?.length" class="category-tree-toggle" type="button" :aria-expanded="searching||!collapsed.has(node.id)" :aria-label="(searching||!collapsed.has(node.id)?'طي ':'توسيع ')+node.name" :disabled="searching" @click="$emit('toggle',node.id)"><AdminIcon name="chevron-down"/></button>
      <span v-else class="category-tree-leaf" aria-hidden="true"><span/></span>
      <span class="category-tree-symbol" aria-hidden="true"><AdminIcon name="layout-grid"/></span>
      <div class="category-tree-name"><strong>{{ node.name }}</strong><small v-if="node.children?.length">{{ node.children.length }} تصنيف فرعي{{ searching?' في النتائج':'' }}</small><small v-else>تصنيف نهائي</small></div>
      <div class="category-tree-meta"><span v-if="node.products_count!=null" class="category-tree-products">{{ node.products_count }} منتج</span><span class="badge" :class="node.is_active?'good':'off'">{{ node.is_active?'نشط':'متوقف' }}</span></div>
      <div v-if="canEdit||canDelete" class="category-tree-actions"><button v-if="canEdit" class="row-button" type="button" :disabled="busy" :aria-label="'تعديل '+node.name" @click="$emit('edit',node)"><AdminIcon name="pencil"/><span>تعديل</span></button><button v-if="canDelete" class="row-button danger" type="button" :disabled="busy" :aria-label="'حذف '+node.name" @click="$emit('delete',node)"><AdminIcon name="trash-2"/><span>حذف</span></button></div>
    </div>
    <ul v-if="node.children?.length&&(searching||!collapsed.has(node.id))" class="category-tree-children">
      <CategoryTreeNode v-for="child in node.children" :key="child.id" :node="child" :collapsed="collapsed" :searching="searching" :busy="busy" :can-edit="canEdit" :can-delete="canDelete" @toggle="$emit('toggle',$event)" @edit="$emit('edit',$event)" @delete="$emit('delete',$event)"/>
    </ul>
  </li>
</template>
