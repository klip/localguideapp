import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueJsx from '@vitejs/plugin-vue-jsx'
import vueDevTools from 'vite-plugin-vue-devtools'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueJsx(),
    vueDevTools(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  css: {
    preprocessorOptions: {
      scss: {
        // Lets stylesheets `@use "pico"` and `@use "breakpoints"` without relative paths,
        // so component <style> blocks can reach the shared partials from any depth.
        loadPaths: [
          fileURLToPath(new URL('./node_modules/@picocss/pico/scss', import.meta.url)),
          fileURLToPath(new URL('./src/assets/styles', import.meta.url)),
        ],
      },
    },
  },
  server: {
    host: '0.0.0.0',
  },
})
