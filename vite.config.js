import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [tailwindcss(), vue(), vueDevTools()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    // Same-origin untuk session Laravel: setiap panggilan /api diteruskan ke
    // backend. Cookie session dan XSRF-TOKEN mengalir biasa, tanpa CORS.
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY_TARGET ?? 'http://localhost:8000',
        changeOrigin: true,
      },
    },
    headers: {
      // Security headers
      // img-src ikut origin API proxy: foto profil (Storage::url) menunjuk ke
      // backend secara absolut, sehingga diblokir 'self' saat dev di :5174.
      'Content-Security-Policy': `default-src 'self'; img-src 'self' data: https: ${process.env.VITE_API_PROXY_TARGET ?? 'http://localhost:8000'}; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; connect-src 'self'`,
      'X-Frame-Options': 'DENY',
      'X-Content-Type-Options': 'nosniff',
      'Referrer-Policy': 'strict-origin-when-cross-origin',
      'Permissions-Policy': 'geolocation=(), microphone=(), camera=()',
    },
  },
  define: {
    'import.meta.env.VITE_APP_API_BASE': JSON.stringify(process.env.VITE_API_PROXY_TARGET ?? 'http://localhost:8000')
  }
})
