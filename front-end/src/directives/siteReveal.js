let observer

export const vSiteReveal = {
  mounted(element) {
    if (!('IntersectionObserver' in window) || matchMedia('(prefers-reduced-motion: reduce)').matches) return

    observer ??= new IntersectionObserver(entries => {
      for (const entry of entries) {
        if (!entry.isIntersecting || !entry.target.isConnected) continue
        observer.unobserve(entry.target)
        entry.target.classList.add('site-revealed')
      }
    }, { threshold: 0.08 })
    observer.observe(element)
  },
  beforeUnmount(element) {
    observer?.unobserve(element)
  },
}
