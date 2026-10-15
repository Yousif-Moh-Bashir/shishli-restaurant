(() => {
  'use strict';
  const $ = selector => document.querySelector(selector);
  const store = window.AdminStore;
  const icon = window.AdminIcons.icon;
  const page = document.body.dataset.page;
  const esc = value => String(value ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = n => `${new Intl.NumberFormat('ar-SA',{numberingSystem:'latn',maximumFractionDigits:2}).format(n)} ريال`;
  const statuses = ['جديد','قيد التحضير','جاهز','مكتمل','ملغي'];
  const stages = statuses.slice(0,4);
  const transitionLabels = ['بدء التحضير','جاهز للاستلام','إكمال الطلب'];
  const recentTransitions = new Map();
  function orderActions(order) {
    const stage = stages.indexOf(order.status);
    return `<div class="order-actions">${stage >= 0 && stage < 3 ? `<button type="button" class="button order-next" data-action="advance" data-id="${order.id}" data-status="${esc(order.status)}">${icon(stage===0?'utensils':'badge-check')} ${transitionLabels[stage]} ${icon('arrow-left')}</button><button type="button" class="row-button danger" data-action="cancel-order" data-id="${order.id}">إلغاء الطلب</button>` : `<span class="order-finished">${icon(order.status==='مكتمل'?'badge-check':'x')} ${order.status==='مكتمل'?'تم إكمال الطلب':'تم إلغاء الطلب'}</span>`}</div>`;
  }
  function orderProgress(order) {
    const current = stages.indexOf(order.status);
    return `<ol class="order-progress" aria-label="مراحل الطلب">${stages.map((stage,index)=>`<li class="${index<current?'done':index===current?'current':''}" ${index===current?'aria-current="step"':''}><span>${index<current?'✓':index+1}</span>${esc(stage)}</li>`).join('')}</ol>`;
  }
  function ordersBoard(orders) {
    if (!orders.length) return '<div class="empty">لا توجد طلبات مطابقة في هذه الحالة.</div>';
    return `<div class="orders-board">${orders.map(order=>`<article class="order-card"><div class="order-card-heading"><div><h2>طلب #${order.id}</h2><small>${esc(order.date)} · ${esc(order.delivery)}</small></div>${badge(order.status,order.status==='مكتمل')}</div><div class="order-customer">${icon('user-round')} ${esc(customerName(order.customerId))}</div><p class="order-items-preview">${order.items.map(item=>`${esc(item.name)} × ${item.quantity}`).join('، ')}</p>${order.status==='ملغي'?'<p class="order-cancelled">هذا الطلب ملغي ولا ينتقل إلى مراحل التجهيز.</p>':orderProgress(order)}<div class="order-card-total"><strong>${money(store.total(order))}</strong><button type="button" class="row-button" data-action="order" data-id="${order.id}">التفاصيل ${icon('arrow-left')}</button></div>${orderActions(order)}</article>`).join('')}</div>`;
  }
  let query = '', filter = '', toastTimer;
  const descriptions = {index:'كل ما تحتاجه لمتابعة المطعم، في مكان واحد.',orders:'راجع الطلبات وتابع مراحل تجهيزها.',products:'إدارة أصناف المنيو والأسعار والتوفر.',offers:'أضف عروضك وحدد سعر العرض وحالته.',customers:'قائمة العملاء وسجل طلباتهم التجريبية.',settings:'بيانات المطعم وأوقات العمل وروابط التواصل.'};
  descriptions.categories = 'نظّم أصناف المنيو وأدر التصنيفات المرتبطة بمنتجاتك.';
  $('#subtitle').textContent = descriptions[page];
  $('#today').textContent = new Intl.DateTimeFormat('ar-SA',{dateStyle:'full',calendar:'gregory'}).format(new Date());
  const nav = $(`[data-nav="${page}"]`); nav.classList.add('active'); nav.setAttribute('aria-current','page');
  const menu = $('#menu-toggle');
  menu.addEventListener('click',()=>{const open=$('#sidebar').classList.toggle('open');menu.setAttribute('aria-expanded',String(open));});
  function closeMenu(){ $('#sidebar').classList.remove('open');menu.setAttribute('aria-expanded','false'); }
  document.addEventListener('click',e=>{if(!e.target.closest('.sidebar,.menu-toggle'))closeMenu();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape')closeMenu();});
  $('#close-dialog').addEventListener('click',()=>$('#editor').close());
  function toast(message){$('#toast').textContent=message;$('#toast').hidden=false;clearTimeout(toastTimer);toastTimer=setTimeout(()=>$('#toast').hidden=true,3500);}
  function commit(next){if(!store.save(next)){toast('تعذر الحفظ في المتصفح. لم تُحفظ التغييرات.');return false;}render();toast('تم حفظ التغييرات في اللوحة التجريبية');return true;}
  const badge = (text,good=false) => `<span class="badge ${good?'good':text==='ملغي'||text==='متوقف'?'off':''}">${esc(text)}</span>`;
  const customerName = id => store.data.customers.find(c=>c.id===id)?.name || 'عميل غير متوفر';
  const productName = id => store.data.products.find(p=>p.id===id)?.name || 'منتج غير متوفر';
  const validImage = value => !value || /^https?:\/\//i.test(value) || /^data:image\/(png|jpeg|webp);base64,[a-z0-9+/=]+$/i.test(value) || /^assets\/images\/[a-z0-9_./-]+$/i.test(value);
  const productImage = product => product.image && validImage(product.image) ? `<img class="product-thumb" src="${esc(product.image)}" alt="${esc(product.name)}" loading="lazy">` : `<span class="product-thumb product-placeholder" aria-label="بدون صورة">${icon('utensils')}</span>`;
  function table(head,rows){return rows.length?`<div class="table-wrap"><table><thead><tr>${head.map(h=>`<th scope="col">${h}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>`:'<p class="empty">لا توجد نتائج مطابقة.</p>';}
  function toolbar(addLabel,options=[]){return `<div class="toolbar"><input id="search" type="search" aria-label="بحث" placeholder="ابحث هنا…" value="${esc(query)}">${options.length?`<select id="filter" aria-label="تصفية النتائج"><option value="">كل الحالات</option>${options.map(o=>`<option ${filter===o?'selected':''}>${esc(o)}</option>`).join('')}</select>`:''}${addLabel?`<button class="button" data-action="add">${icon('plus')} ${addLabel}</button>`:''}</div>`;}
  function ordersTable(orders){return table(['الطلب','العميل','الاستلام','الإجمالي','الحالة',''],orders.map(o=>`<tr><td>#${o.id}<br><small>${esc(o.date)}</small></td><td>${esc(customerName(o.customerId))}</td><td>${esc(o.delivery)}</td><td>${money(store.total(o))}</td><td>${badge(o.status,o.status==='مكتمل')}</td><td><button class="row-button" data-action="order" data-id="${o.id}">عرض التفاصيل ${icon('arrow-left')}</button></td></tr>`));}
  function render(){
    const d=store.data;
    let html='';
    if(page==='categories')html=`<section class="panel">${toolbar('إضافة تصنيف')}${table(['التصنيف','الوصف','عدد المنتجات','الإجراءات'],d.categories.filter(c=>`${c.name} ${c.description||''}`.includes(query)).map(c=>`<tr><td><strong>${esc(c.name)}</strong></td><td class="category-description">${esc(c.description||'—')}</td><td>${d.products.filter(p=>p.category===c.name).length}</td><td><button class="row-button" data-action="edit-category" data-id="${c.id}">${icon('pencil')} تعديل</button><button class="row-button danger" data-action="delete-category" data-id="${c.id}">${icon('trash-2')} حذف</button></td></tr>`))}<p class="form-note">تعديل اسم التصنيف يحدّث منتجاته تلقائيًا. لن يُحذف التصنيف المرتبط بمنتجات؛ انقلها إلى تصنيف آخر أولاً.</p></section>`;
    if(page==='index'){
      const completed=d.orders.filter(o=>o.status==='مكتمل');
      const stats=[['إجمالي الطلبات',d.orders.length,'جميع الطلبات التجريبية'],['طلبات قيد التنفيذ',d.orders.filter(o=>!['مكتمل','ملغي'].includes(o.status)).length,'تحتاج المتابعة'],['قيمة الطلبات المكتملة',money(completed.reduce((s,o)=>s+store.total(o),0)),'ليست مدفوعات فعلية'],['المنتجات المتاحة',d.products.filter(p=>p.active).length,`من ${d.products.length} منتجات`]];
      html=`<div class="stats">${stats.map(s=>`<article class="stat"><div class="stat-heading"><span>${s[0]}</span><span class="stat-icon">${icon(['shopping-bag','clock','badge-check','utensils'][stats.indexOf(s)])}</span></div><strong>${s[1]}</strong><small>${s[2]}</small></article>`).join('')}</div><div class="two-columns"><section class="panel"><div class="panel-head"><h2>آخر الطلبات</h2><a class="row-button" href="orders.html">كل الطلبات ${icon('arrow-left')}</a></div>${ordersTable([...d.orders].reverse().slice(0,5))}</section><section class="panel"><h2>حالة الطلبات</h2>${statuses.map(s=>{const count=d.orders.filter(o=>o.status===s).length;return `<div class="bar-row"><div><span>${s}</span><strong>${count}</strong></div><div class="bar"><span style="width:${d.orders.length?count/d.orders.length*100:0}%"></span></div></div>`;}).join('')}<a class="button secondary" href="offers.html">إدارة العروض</a></section></div>`;
    }
    if(page==='orders')html=`<div class="order-tabs" role="group" aria-label="تصفية الطلبات حسب الحالة">${['',...statuses].map(status=>`<button type="button" data-order-filter="${esc(status)}" aria-pressed="${filter===status}">${status||'كل الطلبات'}<span>${status?d.orders.filter(o=>o.status===status).length:d.orders.length}</span></button>`).join('')}</div><section class="panel orders-panel">${toolbar('')}<p class="order-help">${icon('arrow-left')} انقل الطلب للخطوة التالية مباشرة من بطاقته.</p>${ordersBoard(d.orders.filter(o=>(!filter||o.status===filter)&&`${o.id} ${customerName(o.customerId)}`.includes(query)))}</section>`;
    if(page==='products')html=`<section class="panel">${toolbar('إضافة منتج',['متاح','متوقف'])}${table(['المنتج','التصنيف','السعر','الوزن / السعرات','الحالة','الإجراءات'],d.products.filter(p=>`${p.name} ${p.category}`.includes(query)&&(!filter||p.active===(filter==='متاح'))).map(p=>`<tr><td><div class="product-cell">${productImage(p)}<div><strong>${esc(p.name)}</strong><small class="product-description">${esc(p.description || 'لم يُضف وصف بعد')}</small></div></div></td><td>${esc(p.category)}</td><td>${money(p.price)}</td><td><span>${p.weight == null ? '—' : `${p.weight} جم`}</span><br><small>${p.calories == null ? 'السعرات غير محددة' : `${p.calories} كيلو سعرة`}</small></td><td>${badge(p.active?'متاح':'متوقف',p.active)}</td><td><button class="row-button" data-action="edit" data-id="${p.id}">${icon('pencil')} تعديل</button><button class="row-button danger" data-action="delete" data-id="${p.id}">${icon('trash-2')} حذف</button></td></tr>`))}</section>`;
    if(page==='offers')html=`<section class="panel">${toolbar('إضافة عرض',['مفعّل','متوقف'])}${table(['العرض','المنتج','السعر الأصلي','سعر العرض','الحالة','الإجراءات'],d.offers.filter(o=>`${o.title} ${productName(o.productId)}`.includes(query)&&(!filter||o.active===(filter==='مفعّل'))).map(o=>`<tr><td>${esc(o.title)}</td><td>${esc(productName(o.productId))}</td><td>${money(d.products.find(p=>p.id===o.productId)?.price||0)}</td><td>${money(o.price)}</td><td>${badge(o.active?'مفعّل':'متوقف',o.active)}</td><td><button class="row-button" data-action="edit" data-id="${o.id}">${icon('pencil')} تعديل</button><button class="row-button danger" data-action="delete" data-id="${o.id}">${icon('trash-2')} حذف</button></td></tr>`))}<p class="form-note">هذه العروض تخص معاينة لوحة التحكم فقط. لن تظهر على واجهة المطعم قبل ربط النظامين بخادم.</p></section>`;
    if(page==='customers')html=`<section class="panel">${toolbar('')}${table(['العميل','الجوال','عدد الطلبات','قيمة الطلبات المكتملة',''],d.customers.filter(c=>`${c.name} ${c.phone}`.includes(query)).map(c=>`<tr><td>${esc(c.name)}</td><td>${esc(c.phone)}</td><td>${d.orders.filter(o=>o.customerId===c.id).length}</td><td>${money(d.orders.filter(o=>o.customerId===c.id&&o.status==='مكتمل').reduce((s,o)=>s+store.total(o),0))}</td><td><button class="row-button" data-action="customer" data-id="${c.id}">سجل الطلبات ${icon('arrow-left')}</button></td></tr>`))}</section>`;
    if(page==='settings')html=`<section class="panel"><h2>بيانات المطعم</h2><form id="settings-form"><div class="form-grid">${field('name','اسم المطعم',d.settings.name)}${field('phone','رقم الجوال',d.settings.phone,'tel')}${field('address','العنوان',d.settings.address)}${field('map','رابط الموقع على الخريطة',d.settings.map,'url')}${field('open','وقت الفتح',d.settings.open,'time')}${field('close','وقت الإغلاق',d.settings.close,'time')}</div><p class="form-note">الأوقات قابلة للامتداد لليوم التالي. هذه الإعدادات لا تتصل بالموقع العام حاليًا.</p><button class="button settings-save" type="submit">${icon('badge-check')} حفظ الإعدادات</button></form></section>`;
    $('#content').innerHTML=html;
    $('#search')?.addEventListener('input',e=>{query=e.target.value;render();$('#search').focus();});
    $('#filter')?.addEventListener('change',e=>{filter=e.target.value;render();});
    $('#settings-form')?.addEventListener('submit',e=>{e.preventDefault();const values=Object.fromEntries(new FormData(e.target));if(!/^https?:\/\//i.test(values.map)){toast('استخدم رابط خريطة يبدأ بـ https://');return;}if(!values.name.trim()||!values.address.trim()){toast('أكمل الاسم والعنوان');return;}const next=structuredClone(d);next.settings=values;commit(next);});
  }
  function field(name,label,value='',type='text'){return `<label class="field">${label}<input name="${name}" type="${type}" value="${esc(value)}" required ${type==='number'?'min="0.01" step="0.01"':'maxlength="250"'}></label>`;}
  function modal(title,html,submit){$('#editor-title').textContent=title;const form=$('#editor-form');form.innerHTML=html;form.onsubmit=e=>{e.preventDefault();submit?.(new FormData(form));};$('#editor').showModal();}
  function edit(id){
    const type=page==='products'?'products':'offers';const existing=store.data[type].find(x=>x.id===id);const item=existing||{active:true};
    if(type==='offers'&&!store.data.products.length){toast('أضف منتجًا قبل إنشاء عرض.');return;}
    if(type==='products'&&!store.data.categories.length){toast('أضف تصنيفًا من قسم التصنيفات أولاً.');return;}
    let fields=type==='products'?`${field('name','اسم المنتج',item.name)}<label class="field">التصنيف<select name="category" required><option value="">اختر التصنيف</option>${store.data.categories.map(category=>`<option value="${esc(category.name)}" ${category.name===item.category?'selected':''}>${esc(category.name)}</option>`).join('')}</select></label>${field('price','السعر بالريال',item.price,'number')}<label class="field">الوزن (جرام)<input name="weight" type="number" min="0.01" step="0.01" value="${esc(item.weight)}" placeholder="اختياري"></label><label class="field">السعرات (كيلو سعرة لكل حصة)<input name="calories" type="number" min="0" step="1" value="${esc(item.calories)}" placeholder="اختياري"></label><label class="field">مسببات الحساسية<input name="allergens" maxlength="500" value="${esc(item.allergens)}" placeholder="مثل الحليب أو القمح — اختياري"></label><label class="field field-wide">وصف المنتج<textarea name="description" rows="2" maxlength="1000">${esc(item.description)}</textarea></label><label class="field field-wide">المكونات<textarea name="ingredients" rows="3" maxlength="1500" placeholder="اكتب المكونات وافصل بينها بفواصل">${esc(item.ingredients)}</textarea></label><div class="field field-wide image-editor"><span>صورة المنتج</span><div id="product-image-preview">${productImage(item)}</div><label class="field">رابط الصورة<input id="product-image-url" type="url" placeholder="https://…" value="${esc(item.image?.startsWith('http')?item.image:'')}"></label><label class="field">أو ارفع صورة<input id="product-image-file" type="file" accept="image/png,image/jpeg,image/webp"></label><input type="hidden" name="image" id="product-image-value" value="${esc(item.image)}"><button type="button" class="row-button" id="remove-product-image">إزالة الصورة</button><small>PNG أو JPG أو WebP، حتى 1 ميجابايت. الوزن والسعرات لكل حصة؛ اترك القيم غير المعروفة فارغة.</small></div>`:`${field('title','عنوان العرض',item.title)}<label class="field">المنتج<select name="productId">${store.data.products.map(p=>`<option value="${p.id}" ${p.id===item.productId?'selected':''}>${esc(p.name)} — ${money(p.price)}</option>`).join('')}</select></label>${field('price','سعر العرض',item.price,'number')}`;
    modal(existing?'تعديل البيانات':type==='products'?'إضافة منتج':'إضافة عرض',`<div class="form-grid">${fields}</div><label class="check"><input name="active" type="checkbox" ${item.active?'checked':''}> مفعّل</label><p class="form-note">الحفظ داخل لوحة التحكم التجريبية فقط.</p><div class="form-actions"><button class="button" type="submit">${icon('badge-check')} حفظ</button></div>`,form=>{
      const entry=Object.fromEntries(form);entry.price=Number(entry.price);entry.active=form.has('active');entry.id=id||Math.max(0,...store.data[type].map(x=>x.id))+1;
      if(!Number.isFinite(entry.price)||entry.price<=0||(type==='products'?[entry.name,entry.category]:[entry.title]).some(v=>!v?.trim())){toast('أدخل الاسم والتصنيف والسعر بصورة صحيحة');return;}
      if(type==='products'){
        if(!store.data.categories.some(category=>category.name===entry.category)){toast('اختر تصنيفًا موجودًا في القائمة.');return;}
        entry.weight=entry.weight===''?null:Number(entry.weight);
        entry.calories=entry.calories===''?null:Number(entry.calories);
        if((entry.weight!==null&&(!Number.isFinite(entry.weight)||entry.weight<=0))||(entry.calories!==null&&(!Number.isInteger(entry.calories)||entry.calories<0))){toast('تحقق من الوزن والسعرات الحرارية.');return;}
        if(!validImage(entry.image)){toast('استخدم رابط صورة صحيحًا أو ارفع صورة مدعومة.');return;}
      }
      if(type==='offers'){entry.productId=Number(entry.productId);const p=store.data.products.find(p=>p.id===entry.productId);if(!p||entry.price>=p.price){toast('يجب أن يكون سعر العرض أقل من سعر المنتج.');return;}}
      if(type==='products'&&store.data.offers.some(o=>o.productId===id&&o.price>=entry.price)){toast('عدّل أو احذف عرض المنتج قبل تخفيض سعره إلى سعر العرض أو أقل.');return;}
      const next=structuredClone(store.data);if(existing)next[type]=next[type].map(x=>x.id===id?entry:x);else next[type].push(entry);
      if(commit(next))$('#editor').close();
    });
    if(type==='products'){
      let imageVersion=0;
      const saveButton=$('#editor-form button[type="submit"]');
      const preview=value=>{$('#product-image-value').value=value;$('#product-image-preview').innerHTML=productImage({...item,image:value});};
      $('#product-image-url').addEventListener('input',e=>{imageVersion++;saveButton.disabled=false;$('#product-image-file').value='';preview(e.target.value.trim());});
      $('#remove-product-image').addEventListener('click',()=>{imageVersion++;saveButton.disabled=false;$('#product-image-url').value='';$('#product-image-file').value='';preview('');});
      $('#product-image-file').addEventListener('change',async e=>{
        const file=e.target.files[0];if(!file)return;
        const version=++imageVersion; saveButton.disabled=false;
        if(!['image/png','image/jpeg','image/webp'].includes(file.type)||file.size>1024*1024){e.target.value='';toast('اختر PNG أو JPG أو WebP بحجم لا يتجاوز 1 ميجابايت.');return;}
        saveButton.disabled=true;
        try{
          const value=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(reader.result);reader.onerror=reject;reader.readAsDataURL(file);});
          const image=new Image();image.src=value;await image.decode();
          if(version!==imageVersion||!$('#editor').open||!saveButton.isConnected)return;
          $('#product-image-url').value='';preview(value);
        }catch{if(version===imageVersion){e.target.value='';toast('تعذر قراءة الصورة. اختر ملفًا صالحًا.');}}
        finally{if(version===imageVersion)saveButton.disabled=false;}
      });
    }
  }
  function editCategory(id) {
    const existing=store.data.categories.find(category=>category.id===id);
    modal(existing?'تعديل التصنيف':'إضافة تصنيف',`<div class="form-grid">${field('name','اسم التصنيف',existing?.name||'')}<label class="field field-wide">الوصف (اختياري)<textarea name="description" rows="3" maxlength="500">${esc(existing?.description||'')}</textarea></label></div><div class="form-actions"><button class="button" type="submit">${icon('badge-check')} حفظ التصنيف</button></div>`,form=>{
      const name=String(form.get('name')||'').trim().replace(/\s+/g,' ');
      if(!name){toast('أدخل اسم التصنيف.');return;}
      const normalize=value=>value.normalize('NFKC').trim().replace(/\s+/g,' ').toLocaleLowerCase('ar');
      if(store.data.categories.some(category=>category.id!==id&&normalize(category.name)===normalize(name))){toast('يوجد تصنيف بهذا الاسم بالفعل.');return;}
      const next=structuredClone(store.data);
      const entry={id:existing?id:Math.max(0,...next.categories.map(category=>category.id))+1,name,description:String(form.get('description')||'').trim()};
      if(existing){next.categories=next.categories.map(category=>category.id===id?entry:category);next.products.forEach(product=>{if(product.category===existing.name)product.category=name;});}
      else next.categories.push(entry);
      if(commit(next))$('#editor').close();
    });
  }
  document.addEventListener('click',e=>{
    const tab=e.target.closest('[data-order-filter]');
    if(tab){filter=tab.dataset.orderFilter;render();document.querySelectorAll('[data-order-filter]').forEach(item=>{if(item.dataset.orderFilter===filter)item.focus();});return;}
    const button=e.target.closest('[data-action]');if(!button)return;const id=Number(button.dataset.id),action=button.dataset.action;
    if(action==='edit-category'||(action==='add'&&page==='categories')){editCategory(id);return;}
    if(action==='delete-category'){
      const category=store.data.categories.find(item=>item.id===id);if(!category)return;
      if(store.data.products.some(product=>product.category===category.name)){toast('التصنيف مرتبط بمنتجات. انقلها إلى تصنيف آخر من صفحة المنتجات أولاً.');return;}
      modal('حذف التصنيف',`<p>هل تريد حذف «${esc(category.name)}»؟</p><div class="form-actions"><button class="button" type="submit">تأكيد الحذف</button><button class="button secondary" type="button" data-action="dismiss">تراجع</button></div>`,()=>{
        if(store.data.products.some(product=>product.category===category.name)){toast('لا يمكن حذف تصنيف مرتبط بمنتجات.');return;}
        const next=structuredClone(store.data);next.categories=next.categories.filter(item=>item.id!==id);if(commit(next))$('#editor').close();
      });return;
    }
    if(action==='advance'){
      const order=store.data.orders.find(o=>o.id===id);if(!order)return;
      const stage=stages.indexOf(order.status);
      if(stage<0||stage>=3||button.dataset.status!==order.status||Date.now()-(recentTransitions.get(id)||0)<700)return;
      const next=structuredClone(store.data),updated=next.orders.find(o=>o.id===id);
      updated.status=stages[stage+1];
      updated.history=[...(updated.history||[]),{from:order.status,to:updated.status,at:new Date().toISOString()}];
      if(commit(next)){
        recentTransitions.set(id,Date.now());
        if($('#editor').open)$('#editor').close();
        toast(`الطلب #${id}: ${updated.status}`);
        const target=$(`[data-action="advance"][data-id="${id}"]`)||$(`[data-action="order"][data-id="${id}"]`)||$('[data-order-filter][aria-pressed="true"]');target?.focus();
      }
      return;
    }
    if(action==='cancel-order'){
      const order=store.data.orders.find(o=>o.id===id);if(!order||['مكتمل','ملغي'].includes(order.status))return;
      if($('#editor').open)$('#editor').close();
      modal(`إلغاء الطلب #${id}`,`<p>هل تريد إلغاء هذا الطلب؟ سيتوقف عن الظهور ضمن الطلبات الجاري تجهيزها.</p><div class="form-actions"><button class="button" type="submit">تأكيد إلغاء الطلب</button><button class="button secondary" type="button" data-action="dismiss">الاحتفاظ بالطلب</button></div>`,()=>{
        const next=structuredClone(store.data),updated=next.orders.find(o=>o.id===id);
        if(!updated||['مكتمل','ملغي'].includes(updated.status))return;
        updated.history=[...(updated.history||[]),{from:updated.status,to:'ملغي',at:new Date().toISOString()}];updated.status='ملغي';
        if(commit(next)){$('#editor').close();toast(`تم إلغاء الطلب #${id}`);}
      });return;
    }
    if(action==='dismiss'){$('#editor').close();return;}
    if(action==='add'||action==='edit')edit(id);
    if(action==='delete'){
      const type=page==='products'?'products':'offers';const item=store.data[type].find(x=>x.id===id);if(!item)return;
      modal('تأكيد الحذف',`<p>حذف «${esc(item.name||item.title)}» من بيانات اللوحة؟ ${type==='products'?'ستُحذف عروضه المرتبطة أيضًا، وتبقى تفاصيل الطلبات السابقة محفوظة.':''}</p><div class="form-actions"><button class="button" type="submit">تأكيد الحذف</button></div>`,()=>{const next=structuredClone(store.data);next[type]=next[type].filter(x=>x.id!==id);if(type==='products')next.offers=next.offers.filter(o=>o.productId!==id);if(commit(next))$('#editor').close();});
    }
    if(action==='order'){
      const order=store.data.orders.find(o=>o.id===id);if(!order)return;
      modal(`تفاصيل الطلب #${id}`,`<p>${esc(customerName(order.customerId))} · ${esc(order.delivery)}</p><ul class="detail-list">${order.items.map(i=>`<li>${esc(i.name)} × ${i.quantity} — ${money(i.price*i.quantity)}</li>`).join('')}</ul><p class="summary-total">الإجمالي: ${money(store.total(order))}</p>${order.status==='ملغي'?badge('ملغي'):orderProgress(order)}${orderActions(order)}${order.history?.length?`<details class="order-history"><summary>سجل تغييرات الحالة</summary><ul class="detail-list">${order.history.map(h=>`<li>${esc(h.from)} ← ${esc(h.to)}<br><small>${esc(new Date(h.at).toLocaleString('ar-SA',{calendar:'gregory'}))}</small></li>`).join('')}</ul></details>`:''}`);
    }
    if(action==='customer'){
      const orders=store.data.orders.filter(o=>o.customerId===id);
      modal(`طلبات ${customerName(id)}`,table(['الطلب','الإجمالي','الحالة'],orders.map(o=>`<tr><td>#${o.id}</td><td>${money(store.total(o))}</td><td>${badge(o.status,o.status==='مكتمل')}</td></tr>`)));
    }
  });
  render();
})();
