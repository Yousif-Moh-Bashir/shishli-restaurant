import {orderStatusLabels,orderTypeLabels,paymentStatusLabels} from './orderStatus'
const input=(key,label,type='text',extra={})=>({key,label,type,...extra})
const select=(key,label,values)=>input(key,label,'select',{options:values.map(([value,label])=>({value,label}))})
const bool=(key,label)=>select(key,label,[['','الكل'],['1','نعم'],['0','لا']])
export const paginationFields=[select('per_page','عدد النتائج',[[10,'10'],[20,'20'],[50,'50'],[100,'100']])]
const order=values=>[select('sort','الترتيب',values.map(([value,label])=>[value,label])),select('direction','الاتجاه',[['asc','تصاعدي'],['desc','تنازلي']]),...paginationFields]
export const promotionFilters=[select('discount_type','نوع الخصم',[['','الكل'],['percentage','نسبة'],['fixed','مبلغ'],['fixed_price','سعر خاص']]),...order([['priority','الأولوية'],['name','الاسم'],['created_at','الإنشاء'],['starts_at','البداية'],['ends_at','النهاية'],['used_count','الاستخدام']])]
export const orderFilters=[input('search','بحث الطلب','search',{max:150}),select('status','حالة الطلب',[['','الكل'],...Object.entries(orderStatusLabels)]),select('type','الاستلام',[['','الكل'],...Object.entries(orderTypeLabels)]),select('payment_status','حالة الدفع',[['','الكل'],...Object.entries(paymentStatusLabels)]),input('date_from','من','date'),input('date_to','إلى','date'),...paginationFields]
export const customerFilters=[input('phone','الهاتف','text',{max:40}),input('email','البريد','email',{max:255}),bool('has_orders','له طلبات'),input('registered_from','مسجل من','date'),input('registered_to','مسجل حتى','date'),...order([['created_at','التسجيل'],['name','الاسم'],['orders_count','عدد الطلبات'],['total_spent','الإنفاق'],['last_order_at','آخر طلب']])]
export const inventoryFilters=[bool('is_tracking','متتبع'),...order([['product_name','المنتج'],['available','المتاح'],['on_hand','الموجود'],['updated_at','آخر تحديث']])]
export const cmsFilters=kind=>[...(kind==='pages'?[]:[bool('active','نشط')]),...order(kind==='pages'?[['key','الصفحة'],['title','العنوان'],['created_at','الإنشاء'],['updated_at','التحديث']]:[['sort_order','ترتيب العرض'],['title','العنوان'],['created_at','الإنشاء'],['updated_at','التحديث']])]
