// Local copy of the website Lucide icons; see ../icons-LICENSE.txt.
(() => {
  const paths = {
    'layout-grid': '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    settings: '<path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/><path d="m9 3 1-1h4l1 3 3 1 3-1 2 4-2 2v3l2 2-2 4-3-1-3 1-1 3h-4l-1-3-3-1-3 1-2-4 2-2v-3L1 9l2-4 3 1 3-1Z"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    pencil: '<path d="m16 3 5 5M4 20l4-1L21 6a2.1 2.1 0 0 0-3-3L5 16Z"/>',
    'trash-2': '<path d="M3 6h18M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M5 6l1 14h12l1-14M10 10v6M14 10v6"/>',
    'shopping-bag': '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/>',
    search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'user-round': '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
    menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
    x: '<path d="m18 6-12 12M6 6l12 12"/>',
    'arrow-left': '<path d="M19 12H5m7 7-7-7 7-7"/>',
    'arrow-up-left': '<path d="M7 17V7h10M7 7l10 10"/>',
    utensils: '<path d="M4 3v7a2 2 0 0 0 4 0V3M6 3v19M20 22V3c-4 0-4 8-4 10h4"/>',
    flame: '<path d="M12 3c-1 4 4 6 4 9 0 1-1 2-2 2-2 0-3-2-3-4-3 2-5 4-5 7a6 6 0 0 0 12 0c0-5-3-9-6-14Z"/>',
    drumstick: '<path d="M15.4 15.4c3.3-1 5.6-4 5.6-7.2A5.2 5.2 0 0 0 15.8 3c-3.2 0-6.2 2.3-7.2 5.6a5.3 5.3 0 0 0 6.8 6.8Z"/><path d="m8.5 10.5-5 5a2 2 0 0 0 0 3 2 2 0 0 0 3 2l5-5"/>',
    'badge-check': '<path d="m9 12 2 2 4-4M12 2l3 2 4 .5.5 4L22 12l-2.5 3.5-.5 4-4 .5-3 2-3-2-4-.5-.5-4L2 12l2.5-3.5.5-4L9 4Z"/>',
    truck: '<path d="M1 3h14v13H1zM15 8h4l3 4v4h-7"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
    'map-pin': '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
    phone: '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7a2 2 0 0 1 1.7 2Z"/>',
    clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    instagram: '<rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17.5 6.5h.01"/>',
    'music-2': '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
    ghost: '<path d="M9 10h.01M15 10h.01M12 2a8 8 0 0 0-8 8v12l4-3 4 3 4-3 4 3V10a8 8 0 0 0-8-8Z"/>',
    'message-circle': '<path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"/>'
  };
  const icon = name => `<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">${paths[name] || paths['shopping-bag']}</svg>`;
  window.AdminIcons = { icon, render(root = document) { root.querySelectorAll('[data-icon]').forEach(element => element.outerHTML = icon(element.dataset.icon)); } };
  window.AdminIcons.render();

})();
