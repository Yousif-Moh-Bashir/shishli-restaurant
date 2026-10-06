import {computed} from 'vue'
import {usePublicSettingsStore} from '../stores/publicSettings'
import {useBranchStore} from '../stores/branch'
export function useRestaurantInfo(){
 const settings=usePublicSettingsStore(),branch=useBranchStore();void settings.load();void branch.load()
 return computed(()=>{
  const values=settings.data?.restaurant
  const phone=values?.phone||'',whatsapp=values?.whatsapp?.replace(/[^0-9]/g,'')||''
  const location=branch.selectedBranch?.location,latitude=Number(location?.latitude),longitude=Number(location?.longitude)
  const mapHref=location?.latitude!=null&&location?.longitude!=null&&Number.isFinite(latitude)&&Number.isFinite(longitude)?'https://www.google.com/maps/search/?api=1&query='+encodeURIComponent(latitude+','+longitude):undefined
  return {name:values?.name||'مطعم شيشلي',phone,phoneHref:phone?'tel:'+phone:undefined,whatsappHref:whatsapp?'https://wa.me/'+whatsapp:undefined,email:values?.email||null,address:location?.address||'',mapHref,hours:'',hoursNote:''}
 })
}
