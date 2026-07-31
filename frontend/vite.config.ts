import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
export default defineConfig({
  plugins: [
    vue(),
    // vite-plugin-vue-devtools is intentionally disabled. Its floating overlay
    // is `position: fixed; z-index: 2147483645` and is appended to <body>, and
    // while the plugin hides its own anchor in print it never hides the frame —
    // so every printed page gained a blank trailing page containing just the
    // widget. The package is still installed; re-add vueDevTools() here to
    // switch it back on, and check the print output if you do.
    tailwindcss(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    },
  },
  build: {
    rollupOptions: {
      // Silence harmless `/* #__PURE__ */` annotation warnings emitted by
      // third-party deps (e.g. @vueuse/core) under Rolldown. Our own source is
      // still surfaced.
      onwarn(warning, defaultHandler) {
        if (
          warning.code === 'INVALID_ANNOTATION' &&
          warning.id?.includes('node_modules')
        ) {
          return
        }
        defaultHandler(warning)
      },
    },
  },
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost:80',
        changeOrigin: true,
      },
      '/sanctum': {
        target: 'http://localhost:80',
        changeOrigin: true,
      },
    },
  },
})
