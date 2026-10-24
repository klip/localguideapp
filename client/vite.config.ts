import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueJsx from '@vitejs/plugin-vue-jsx'
import vueDevTools from 'vite-plugin-vue-devtools'

/** An origin the auth service and the gateway accept (lib/Origins.php). */
const DEV_ORIGIN = 'http://localhost:5173'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), vueJsx(), vueDevTools()],
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
    // The app is served at https://www.belikelocal.club in development too:
    // nginx (docker-lemp nginx/conf.d/belikelocal.club.conf) terminates TLS
    // and proxies here. Vite refuses unknown Host headers, so allow it, and
    // point hot reload's socket at nginx's port (wss://…:443) — open
    // localhost:5173 directly and the page still works, without hot reload.
    allowedHosts: ['www.belikelocal.club'],
    hmr: { clientPort: 443, protocol: 'wss' },
    // Sign-in (`POST /auth/*`, HttpOnly cookies) must be on the app's own
    // origin: forward it to the auth service's browser endpoint
    // (auth/public.php, nginx :8083 on the docker network).
    proxy: {
      '/auth/': {
        target: process.env.AUTH_PROXY_TARGET ?? 'http://nginx:8083',
        xfwd: true,
        // A page on another address (a phone through browser-sync) sends its
        // own Origin, which the auth service doesn't know; through this dev
        // proxy it's the dev server's, which it does (lib/Origins.php).
        configure: (proxy) => {
          proxy.on('proxyReq', (req) => req.setHeader('origin', DEV_ORIGIN))
        },
      },
      // The API socket, for a page that can't reach api.belikelocal.club
      // (src/plugins/socket.ts) — straight to the gateway.
      '/ws': {
        target: process.env.WS_PROXY_TARGET ?? 'http://ws:8090',
        ws: true,
        xfwd: true,
        configure: (proxy) => {
          proxy.on('proxyReqWs', (req) => req.setHeader('origin', DEV_ORIGIN))
        },
      },
    },
  },
})
