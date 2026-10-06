import {readFile,writeFile,readdir} from 'node:fs/promises'
import path from 'node:path'
import {fileURLToPath} from 'node:url'
import {parse} from '@vue/compiler-sfc'
import {parse as parseTemplate} from '@vue/compiler-dom'
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..')
async function files(directory){const entries=await readdir(directory,{withFileTypes:true});return (await Promise.all(entries.map(entry=>entry.isDirectory()?files(path.join(directory,entry.name)):entry.name.endsWith('.vue')?[path.join(directory,entry.name)]:[]))).flat()}
let headers=0,tables=0,actions=0
for(const file of [...await files(path.join(root,'src/views/admin')),...await files(path.join(root,'src/components/admin'))]){
 if(file.endsWith('AdminPageHeader.vue'))continue
 let source=await readFile(file,'utf8')
 const {descriptor}=parse(source)
 if(!descriptor.template)continue
 const ast=parseTemplate(descriptor.template.content),edits=[]
 const start=descriptor.template.loc.start.offset
 function walk(node){
  if(node.type===1){
   const classes=node.props.find(prop=>prop.type===6&&prop.name==='class')?.value?.content||''
   if(node.tag==='div'&&classes.split(' ').includes('page-heading')){
    const text=node.loc.source,opening=text.indexOf('>')+1,closing=text.lastIndexOf('</div>')
    edits.push({start:start+node.loc.start.offset,end:start+node.loc.start.offset+opening,text:'<AdminPageHeader'+text.slice(4,opening)})
    edits.push({start:start+node.loc.start.offset+closing,end:start+node.loc.end.offset,text:'</AdminPageHeader>'});headers++
   }
   if(node.tag==='table'&&!node.props.some(prop=>prop.type===7&&prop.name==='admin-table')){edits.push({start:start+node.loc.start.offset+6,end:start+node.loc.start.offset+6,text:' v-admin-table'});tables++}
   if(node.tag==='td'&&!node.children.some(child=>child.type===1&&child.props.some(prop=>prop.type===6&&prop.name==='class'&&prop.value?.content==='admin-cell-value'))){
    const opening=node.loc.source.indexOf('>')+1,closing=node.loc.source.lastIndexOf('</td>')
    edits.push({start:start+node.loc.start.offset+opening,end:start+node.loc.start.offset+opening,text:'<div class="admin-cell-value">'})
    edits.push({start:start+node.loc.start.offset+closing,end:start+node.loc.start.offset+closing,text:'</div>'})
   }
   if(node.tag==='td'&&node.children.filter(child=>child.type===1&&['button','template','RouterLink'].includes(child.tag)).length>1){
    const opening=node.loc.source.indexOf('>')+1,closing=node.loc.source.lastIndexOf('</td>')
    edits.push({start:start+node.loc.start.offset+opening,end:start+node.loc.start.offset+opening,text:'<div class="admin-row-actions">'})
    edits.push({start:start+node.loc.start.offset+closing,end:start+node.loc.start.offset+closing,text:'</div>'});actions++
   }
  }
  for(const child of node.children||[])walk(child)
 }
 walk(ast)
 if(!edits.length)continue
 for(const edit of edits.sort((a,b)=>b.start-a.start))source=source.slice(0,edit.start)+edit.text+source.slice(edit.end)
 if(source.includes('<AdminPageHeader')&&!source.includes('import AdminPageHeader')){
  let relative=path.relative(path.dirname(file),path.join(root,'src/components/admin/AdminPageHeader.vue')).replaceAll('\\','/')
  if(!relative.startsWith('.'))relative='./'+relative
  source=source.replace('<script setup>',`<script setup>\nimport AdminPageHeader from '${relative}'`)
 }
 await writeFile(file,source)
}
console.log(JSON.stringify({headers,tables,actions}))
