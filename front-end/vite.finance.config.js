import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'

function financeHtmlFallback(server) {
  server.middlewares.use((request, _response, next) => {
    const path = (request.url || '').split('?')[0]
    if (request.headers.accept?.includes('text/html') && !path.startsWith('/api/') && !path.includes('.')) request.url = '/finance.html'
    next()
  })
}

export default defineConfig({
  plugins: [vue(), { name: 'finance-html-fallback', configureServer: financeHtmlFallback, configurePreviewServer: financeHtmlFallback }],
  define: { 'import.meta.env.VITE_API_BASE_URL': JSON.stringify('/api/v1') },
  build: { outDir: 'dist-finance', rollupOptions: { input: fileURLToPath(new URL('./finance.html', import.meta.url)) } },
  server: { host: '127.0.0.1', port: 5174, open: false, proxy: { '/api': process.env.FINANCE_DEV_API_ORIGIN || 'http://127.0.0.1:8000' } },
})
