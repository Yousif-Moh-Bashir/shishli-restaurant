import { createSSRApp, h, shallowRef } from 'vue'

export function hydratePrerender(router) {
  const snapshot = shallowRef(null)
  const root = document.getElementById('prerender-root')
  const data = document.getElementById('prerender-data')
  const app = document.getElementById('app')
  if (!root || !data || !app) return snapshot

  const page = JSON.parse(data.textContent)
  snapshot.value = page
  const image = (src, alt, loading) => h('img', { src, alt, loading })
  const link = ({ href, label }) => h('a', { href }, label)
  const view = createSSRApp({
    render() {
      const children = [h('h1', page.heading)]
      if (page.image) children.push(image(page.image, page.heading))
      if (page.intro) children.push(h('p', page.intro))
      for (const section of page.sections) children.push(h('section', [h('h2', section.title), h('p', section.text)]))
      if (page.items.length) children.push(h('ul', page.items.map(item => {
        const content = [item.href ? link(item) : h('span', item.label)]
        if (item.image) content.push(image(item.image, item.label, 'lazy'))
        if (item.text) content.push(h('p', item.text))
        return h('li', content)
      })))
      children.push(h('nav', { 'aria-label': 'روابط الموقع' }, [
        h('ul', page.links.map(item => h('li', [link(item)]))),
      ]))
      return h('main', { class: 'container section-space' }, children)
    },
  })
  view.mount(root)
  app.hidden = true

  function revealApp() {
    if (app.querySelector('main h1, main section')) {
      observer.disconnect()
      view.unmount()
      root.remove()
      data.remove()
      app.hidden = false
    }
  }

  const observer = new MutationObserver(revealApp)
  observer.observe(app, { childList: true, subtree: true })
  const removeHook = router.afterEach((to, from, failure) => {
    if (!failure && from.matched.length && to.path !== page.path) {
      snapshot.value = null
      removeHook()
    }
  })
  return snapshot
}
