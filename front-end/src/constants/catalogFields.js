const text = (key, label, max, extra = {}) => ({ key, label, type: 'text', max, nullable: true, ...extra })
const number = (key, label, min = 0, max = 2147483647, extra = {}) => ({ key, label, type: 'number', min, max, step: '1', default: 0, ...extra })
const flag = (key, label, value = true) => ({ key, label, type: 'checkbox', default: value })
const name = max => text('name', 'الاسم', max, { required: true, nullable: false })
const slug = max => text('slug', 'اسم الرابط', max, { pattern: '[a-z0-9]+(-[a-z0-9]+)*', advanced: true, placeholder: 'يُولّد تلقائيًا عند الحفظ' })
const sort = number('sort_order', 'ترتيب العرض')
const money = (key, label) => number(key, label, 0, '99999999.99', { step: '0.01', money: true, default: '0.00' })
export const catalogFields = {
  branches: [name(150), slug(180), text('phone','الهاتف',20),text('whatsapp','واتساب',20),text('city','المدينة',100),text('district','الحي',100),text('address','العنوان',1000,{type:'textarea'}),number('latitude','خط العرض',-90,90,{step:'any',nullable:true,default:''}),number('longitude','خط الطول',-180,180,{step:'any',nullable:true,default:''}),{...sort,max:4294967295},flag('is_active','الفرع نشط'),flag('accepts_orders','يستقبل الطلبات'),flag('supports_pickup','يدعم الاستلام'),flag('supports_delivery','يدعم التوصيل')],
  categories: [name(150),slug(180),{key:'parent_uuid',label:'القسم الأب',type:'category',nullable:true},text('description','الوصف',2000,{type:'textarea'}),text('image','رابط أو مسار صورة القسم',255),sort,flag('is_active','نشط')],
  products: [name(180),slug(200),text('sku','رمز المنتج SKU',100),{key:'category_uuid',label:'التصنيف',type:'category',required:true},money('base_price','السعر الأساسي بالريال'),text('short_description','الوصف المختصر',500,{type:'textarea'}),text('description','الوصف الكامل',5000,{type:'textarea'}),sort,number('preparation_time','وقت التحضير بالدقائق',0,1440,{nullable:true,default:''}),flag('is_active','نشط'),flag('is_available','متوفر عالميًا'),flag('is_featured','منتج مميز',false),flag('tracks_inventory','يتطلب متابعة المخزون',false)],
  groups: [name(150),slug(180),{key:'type',label:'نوع الاختيار',type:'select',default:'single',choices:[['single','اختيار واحد'],['multiple','اختيارات متعددة']],required:true},flag('is_required','اختيار إلزامي',false),number('min_select','الحد الأدنى للاختيارات'),number('max_select','الحد الأقصى للاختيارات',1,2147483647,{nullable:true,default:1}),sort,flag('is_active','نشطة')],
  values: [name(150),slug(180),money('price_modifier','زيادة السعر بالريال'),flag('is_default','اختيار افتراضي',false),flag('is_active','نشط'),sort],
  assignment: [{key:'is_required_override',label:'الإلزام لهذا المنتج',type:'select',nullable:true,choices:[['','حسب المجموعة'],['1','إلزامي'],['0','اختياري']],booleanOverride:true},number('min_select_override','الحد الأدنى الخاص',0,2147483647,{nullable:true,default:''}),number('max_select_override','الحد الأقصى الخاص',1,2147483647,{nullable:true,default:''}),sort],
  branchProduct: [money('price_override','سعر الفرع (فارغ = السعر الأساسي)'),flag('is_available','متوفر في الفرع')],
}
catalogFields.branchProduct[0] = {...catalogFields.branchProduct[0],nullable:true,default:''}
export function initialForm(fields, record = {}) {
  return Object.fromEntries(fields.map(field => {
    let value = record[field.key]
    if (field.booleanOverride) value = value == null ? '' : value ? '1' : '0'
    return [field.key, value ?? field.default ?? '']
  }))
}
export function formPayload(fields, form) {
  return Object.fromEntries(fields.map(field => {
    let value = form[field.key]
    if (field.nullable && (value === '' || value == null)) value = null
    else if (field.booleanOverride) value = value === '1'
    else if (field.type === 'number' && !field.money) value = Number(value)
    else if (field.money) value = String(value) // Decimal text, never client arithmetic.
    return [field.key, value]
  }))
}
