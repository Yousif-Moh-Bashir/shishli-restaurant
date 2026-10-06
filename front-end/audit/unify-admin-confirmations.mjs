import {readFile,writeFile,readdir} from 'node:fs/promises'
import path from 'node:path'
import {fileURLToPath} from 'node:url'
import {parse as parseSfc} from '@vue/compiler-sfc'
import {parse} from '@babel/parser'
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..')
async function files(dir){return(await Promise.all((await readdir(dir,{withFileTypes:true})).map(entry=>entry.isDirectory()?files(path.join(dir,entry.name)):entry.name.endsWith('.vue')?[path.join(dir,entry.name)]:[]))).flat()}
let count=0
for(const file of [...await files(path.join(root,'src/views/admin')),...await files(path.join(root,'src/components/admin'))]){
 let source=await readFile(file,'utf8'),{descriptor}=parseSfc(source)
 if(!descriptor.scriptSetup)continue
 const script=descriptor.scriptSetup,ast=parse(script.content,{sourceType:'module'}),edits=[]
 function walk(node,inAsync=false){
  if(!node||typeof node!=='object')return
  if(['FunctionDeclaration','FunctionExpression','ArrowFunctionExpression'].includes(node.type))inAsync=node.async
  if(node.type==='CallExpression'&&node.callee.type==='Identifier'&&node.callee.name==='confirm'&&inAsync){
   edits.push({start:script.loc.start.offset+node.start,end:script.loc.start.offset+node.end,text:'(await confirmAction'+script.content.slice(node.callee.end,node.end)+')'});count++
  }
  for(const [key,value] of Object.entries(node))if(!['loc','start','end'].includes(key)){if(Array.isArray(value))for(const item of value)walk(item,inAsync);else if(value?.type)walk(value,inAsync)}
 }
 walk(ast)
 if(!edits.length)continue
 for(const edit of edits.sort((a,b)=>b.start-a.start))source=source.slice(0,edit.start)+edit.text+source.slice(edit.end)
 source=source.replace('<script setup>',"<script setup>\nimport {inject as injectAdminConfirmation} from 'vue'\nconst {confirmAction}=injectAdminConfirmation('admin')")
 await writeFile(file,source)
}
console.log(JSON.stringify({confirmations:count}))
