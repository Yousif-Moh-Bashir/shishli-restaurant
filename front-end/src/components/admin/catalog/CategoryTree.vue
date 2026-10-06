<script setup>
import { computed, ref } from 'vue'
import AdminSectionHeading from '../AdminSectionHeading.vue'
import AdminIcon from '../AdminIcon.vue'
import AppSkeleton from '../../common/AppSkeleton.vue'
import ApiFeedback from './ApiFeedback.vue'
import CategoryTreeNode from './CategoryTreeNode.vue'
const props=defineProps({nodes:{type:Array,default:()=>[]},loading:Boolean,error:Object,busy:Boolean,canEdit:Boolean,canDelete:Boolean})
defineEmits(['retry','edit','delete'])
const query=ref(''),collapsed=ref(new Set())
const flat=computed(()=>{const collect=nodes=>nodes.flatMap(node=>[node,...collect(node.children||[])]);return collect(props.nodes)})
const searching=computed(()=>Boolean(query.value.trim()))
const filtered=computed(()=>{
  const term=query.value.trim().toLocaleLowerCase('ar')
  if(!term)return props.nodes
  function filter(nodes){return nodes.flatMap(node=>{const children=filter(node.children||[]);return node.name.toLocaleLowerCase('ar').includes(term)?[node]:children.length?[{...node,children}]:[]})}
  return filter(props.nodes)
})
function toggle(id){const next=new Set(collapsed.value);next.has(id)?next.delete(id):next.add(id);collapsed.value=next}
function collapseAll(){collapsed.value=new Set(flat.value.filter(node=>node.children?.length).map(node=>node.id))}
</script>
<template>
  <section class="panel catalog-section category-tree-panel" :aria-busy="loading">
    <AdminSectionHeading icon="layout-grid">شجرة التصنيفات</AdminSectionHeading>
    <p class="category-tree-intro">استعرض الأقسام الرئيسية وتصنيفاتها الفرعية، وافتح أي مستوى لإدارة محتواه.</p>
    <div class="category-tree-toolbar"><label class="category-tree-search"><AdminIcon name="search"/><input v-model="query" type="search" maxlength="150" aria-label="بحث في شجرة التصنيفات" placeholder="ابحث عن تصنيف داخل الشجرة…"></label><div class="category-tree-controls"><button class="row-button" type="button" :disabled="searching||loading||!nodes.length" @click="collapsed=new Set()">توسيع الكل</button><button class="row-button" type="button" :disabled="searching||loading||!nodes.length" @click="collapseAll">طي الكل</button></div></div>
    <div class="category-tree-summary" role="status"><span>{{ flat.length }} تصنيف <span aria-hidden="true">·</span> {{ nodes.length }} أقسام رئيسية</span><span v-if="searching">نتائج البحث مع أقسامها الرئيسية <button class="text-button" type="button" @click="query=''">مسح البحث</button></span></div>
    <AppSkeleton v-if="loading" variant="list" :rows="3" label="جارٍ تحميل شجرة التصنيفات…"/>
    <ApiFeedback v-if="error" :error="error" @retry="$emit('retry')"/>
    <ul v-if="filtered.length" class="category-tree-roots" aria-label="التصنيفات الرئيسية والفرعية"><CategoryTreeNode v-for="node in filtered" :key="node.id" :node="node" :collapsed="collapsed" :searching="searching" :busy="busy||loading" :can-edit="canEdit" :can-delete="canDelete" @toggle="toggle" @edit="$emit('edit',$event)" @delete="$emit('delete',$event)"/></ul>
    <p v-else-if="!loading&&!error" class="category-tree-empty">{{ searching?'لا توجد تصنيفات مطابقة لهذا البحث.':'لم تُضف تصنيفات بعد. ابدأ بإضافة تصنيف من أعلى الصفحة.' }}</p>
  </section>
</template>
<style scoped>
.category-tree-intro{color:var(--muted);font-size:13px;margin:0 0 18px}
.category-tree-toolbar{display:flex;gap:12px;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap}
.category-tree-search{display:flex;align-items:center;gap:9px;flex:1;min-width:200px;max-width:480px;border:1px solid var(--line);border-radius:8px;background:var(--admin-surface);padding-inline:12px}
.category-tree-search .icon{width:18px;height:18px;color:var(--muted)}
.category-tree-search input{width:100%;min-width:0;border:0;background:transparent;padding:11px 0;font:inherit;font-size:13px}
.category-tree-controls{display:flex;gap:8px}.category-tree-controls .row-button{margin:0}
.category-tree-summary{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:12px;color:var(--muted);padding-bottom:14px}
.category-tree-summary .text-button{margin-inline-start:8px;color:var(--primary)}
.category-tree-empty{padding:28px;text-align:center;color:var(--muted);background:var(--admin-surface);border:1px dashed var(--line);border-radius:9px}
.category-tree-roots,:deep(.category-tree-children){list-style:none;margin:0;padding:0}
.category-tree-roots{display:grid;gap:12px}
:deep(.category-tree-children){margin:8px 20px 0 0;padding-inline-start:16px;border-inline-start:1px solid var(--line);display:grid;gap:8px}
:deep(.category-tree-row){display:flex;align-items:center;gap:10px;padding:12px;background:var(--admin-surface);border:1px solid var(--line);border-radius:9px;min-width:0}
:deep(.category-tree-roots>.category-tree-node>.category-tree-row){background:#f8efe6}
:deep(.category-tree-toggle),:deep(.category-tree-leaf){width:30px;height:30px;flex-shrink:0;display:grid;place-items:center;border:0;border-radius:6px;background:transparent;color:var(--primary)}
:deep(.category-tree-toggle:hover){background:#5a17250c}:deep(.category-tree-toggle .icon){width:18px;height:18px}
:deep(.category-tree-toggle[aria-expanded=false] .icon){transform:rotate(90deg)}
:deep(.category-tree-leaf>span){width:5px;height:5px;background:var(--muted);border-radius:50%;opacity:.45}
:deep(.category-tree-symbol){display:grid;place-items:center;width:34px;height:34px;border-radius:8px;background:#5a172509;color:var(--primary);flex-shrink:0}
:deep(.category-tree-symbol .icon){width:18px;height:18px}
:deep(.category-tree-name){flex:1;min-width:0}:deep(.category-tree-name strong){display:block;font-size:14px;overflow-wrap:anywhere}:deep(.category-tree-name small){display:block;font-size:11px;color:var(--muted);margin-top:3px}
:deep(.category-tree-meta),:deep(.category-tree-actions){display:flex;align-items:center;gap:8px;flex-wrap:wrap}
:deep(.category-tree-products){font-size:11px;color:var(--muted)}:deep(.category-tree-actions .row-button){margin:0;font-size:11px;padding:7px 9px}:deep(.category-tree-actions .icon){width:14px;height:14px}
@media(max-width:700px){.category-tree-search{max-width:none;flex-basis:100%}.category-tree-controls{width:100%}.category-tree-controls .row-button{flex:1}:deep(.category-tree-row){gap:8px;flex-wrap:wrap;padding:10px}:deep(.category-tree-symbol){display:none}:deep(.category-tree-name){flex-basis:calc(100% - 40px)}:deep(.category-tree-meta){margin-inline-start:38px}:deep(.category-tree-actions){margin-inline-start:auto}:deep(.category-tree-children){margin-inline-start:10px;padding-inline-start:8px}}
</style>
