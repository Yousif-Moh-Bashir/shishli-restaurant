export const addressFields = [
  ['label','اسم العنوان',100], ['recipient_name','اسم المستلم',150,true], ['phone','رقم الهاتف',20,true],
  ['city','المدينة',100,true], ['district','الحي',100,true], ['street','الشارع',200], ['building_number','رقم المبنى',50],
  ['floor','الطابق',50], ['apartment','الشقة',50], ['landmark','علامة مميزة',255], ['notes','ملاحظات العنوان',1000],
].map(([key,label,max,required])=>({key,label,max,required,type:key==='notes'?'textarea':key==='phone'?'tel':'text'}))
export function addressForm(record = {}) {
  return { ...Object.fromEntries(addressFields.map(field=>[field.key,record[field.key]||''])), latitude:record.location?.latitude??'', longitude:record.location?.longitude??'', is_default:record.is_default===true }
}
export function addressPayload(form, saved = true) {
  const body=Object.fromEntries(addressFields.filter(field=>saved||field.key!=='label').map(field=>[field.key,form[field.key]?.trim()||null]))
  body.latitude=form.latitude===''||form.latitude==null?null:Number(form.latitude)
  body.longitude=form.longitude===''||form.longitude==null?null:Number(form.longitude)
  if(saved)body.is_default=form.is_default===true
  return body
}
export function quoteAddress(form) {
  const full=addressPayload(form,false)
  return {city:full.city,district:full.district,latitude:full.latitude,longitude:full.longitude}
}
export function completeAddress(form) {
  return ['recipient_name','phone','city','district'].every(key=>typeof form[key]==='string'&&form[key].trim().length>0)
    && ((form.latitude===''&&form.longitude==='') || (form.latitude!==''&&form.longitude!==''&&Number.isFinite(Number(form.latitude))&&Number.isFinite(Number(form.longitude))&&Math.abs(Number(form.latitude))<=90&&Math.abs(Number(form.longitude))<=180))
}
