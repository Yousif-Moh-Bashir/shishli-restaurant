import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  server: { proxy: { '/map-geocoding': 'http://127.0.0.1:8088' } },
})
