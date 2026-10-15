(() => {
  const initial = {
    products: [
      {id:1,name:'نصف دجاج تركي',category:'الدجاج التركي',price:17,active:true},
      {id:2,name:'كباب لحم',category:'المشويات',price:25,active:true},
      {id:3,name:'مشكل مشويات',category:'المشويات',price:45,active:true},
      {id:4,name:'شيش طاووق',category:'الشيش',price:20,active:true},
      {id:5,name:'دجاج تركي مع أرز',category:'الدجاج التركي',price:25,active:true}
    ],
    offers:[{id:1,productId:4,title:'عرض الشيش التجريبي',price:16,active:true}],
    customers:[{id:1,name:'عميل تجريبي ١',phone:'غير متوفر'},{id:2,name:'عميل تجريبي ٢',phone:'غير متوفر'}],
    orders:[{id:1001,customerId:1,status:'جديد',delivery:'استلام',items:[{name:'شيش طاووق',quantity:2,price:16}],date:'2026-09-22'},{id:1002,customerId:2,status:'قيد التحضير',delivery:'توصيل',items:[{name:'مشكل مشويات',quantity:1,price:45}],date:'2026-09-22'},{id:1003,customerId:1,status:'مكتمل',delivery:'استلام',items:[{name:'كباب لحم',quantity:2,price:25}],date:'2026-09-21'}],
    settings:{name:'مطعم شيشلي',phone:'0551040122',address:'المصيف - بجوار إشارة المصيف',map:'https://maps.app.goo.gl/yS1bHr8mAG1dNf2t9',open:'11:00',close:'02:00'}
  };
  const key = 'shishli.dashboard.v1';
  let data = structuredClone(initial);
  try {
    const stored = JSON.parse(localStorage.getItem(key));
    if (stored && ['products','offers','customers','orders'].every(k => Array.isArray(stored[k])) && stored.settings && typeof stored.settings === 'object') data = stored;
  } catch {}
  // Add optional product details without overwriting previously saved records.
  data.products = data.products.map(product => ({
    description:'', ingredients:'', weight:null, calories:null, allergens:'', image:'',
    ...product
  }));
  // Migrate existing product categories without resetting saved dashboard data.
  if (!Array.isArray(data.categories)) data.categories = [];
  data.products.forEach(product => {
    if (product.category && !data.categories.some(category => category.name === product.category)) {
      data.categories.push({id:Math.max(0,...data.categories.map(category=>category.id))+1,name:product.category,description:''});
    }
  });
  window.AdminStore = {
    data,
    save(next) {
      try { localStorage.setItem(key, JSON.stringify(next)); this.data = next; return true; }
      catch { return false; }
    },
    total: order => order.items.reduce((sum,item) => sum + item.price * item.quantity,0)
  };
})();
