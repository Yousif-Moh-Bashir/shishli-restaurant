// Mobile labels follow the rendered header, including permission-dependent columns.
const observers=new WeakMap()
function labelTable(table){
  const headers=[...(table.tHead?.rows[0]?.cells||[])].map(cell=>cell.textContent.trim())
  table.classList.add('admin-data-table')
  table.setAttribute('role','table')
  for(const cell of table.tHead?.querySelectorAll('th')||[])cell.setAttribute('scope','col')
  for(const body of table.tBodies)for(const row of body.rows){
    row.setAttribute('role','row')
    for(const [index,cell] of [...row.cells].entries()){
      cell.setAttribute('role',cell.tagName==='TH'?'rowheader':'cell')
      cell.dataset.label=headers[index]||(row.cells[0]?.tagName==='TH'?row.cells[0].textContent.trim():'الإجراءات')
    }
  }
}
export const adminTable={
  mounted(table){labelTable(table);const observer=new MutationObserver(()=>labelTable(table));observer.observe(table,{childList:true,subtree:true,characterData:true});observers.set(table,observer)},
  updated:labelTable,
  unmounted(table){observers.get(table)?.disconnect();observers.delete(table)},
}
