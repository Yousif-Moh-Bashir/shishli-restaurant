export const tables = ['branches','products','categories','product_images','branch_products','option_groups','option_values','product_option_groups','users','roles','permissions','model_has_roles','model_has_permissions','role_has_permissions','restaurant_settings','orders']
export const columns = Object.fromEntries(tables.map(table => [table,
  table === 'product_images' ? [['id','bigint'],['product_id','bigint'],['disk','varchar'],['path','varchar']]
    : table === 'users' ? [['id','bigint'],['password','varchar']]
      : table === 'products' ? [['id','bigint'],['base_price','decimal']] : [['id','bigint']]]))
export function discovery(missingDisk = false) {
  return tables.flatMap(table => columns[table].filter(([name]) => !(missingDisk && table === 'product_images' && name === 'disk'))
    .map(([name,type],i) => [table,'BASE TABLE',name,i+1,type,'NO']))
}
export const hex = s => Buffer.from(String(s)).toString('hex').toUpperCase()
export function records(mutate = false) {
  const result = [['start','1',[]],['schema','database',['utf8mb4','utf8mb4_unicode_ci']]]
  for (const table of tables) {
    result.push(['schema','tables',[table,'BASE TABLE','InnoDB','utf8mb4_unicode_ci']])
    columns[table].forEach(([name,type],i) => result.push(['schema','columns',[table,name,i+1,type,'NO',null,'','','','']]))
    result.push(['table',table,columns[table].map(([name]) => name)])
    const row = table === 'product_images' ? [1,1,'public','products/one.png']
      : table === 'users' ? [1, mutate ? 'CHANGED_PRIVATE_VALUE' : 'FAKE_PRIVATE_VALUE']
        : table === 'products' ? [1,'12.00'] : [1]
    result.push(['row',table,row.map(hex)])
  }
  result.push(['end','1',[]])
  return result
}
export const jsonl = rows => rows.map(row => JSON.stringify(row)).join('\n')+'\n'
