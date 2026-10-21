import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// The CMS (docs/auth-cms-plan.md): its own build, on its own origin
// (https://admin.belikelocal.club, in development too — nginx proxies here;
// localhost:5174 also works). Staff sign-in
// (`/auth/*`, the CMS cookie) and the API (`/api`) are same-origin: in
// development Vite forwards them to nginx on the docker network.
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5174,
    strictPort: true,
    // Served at https://admin.belikelocal.club through nginx (TLS there), like
    // the app — see client/vite.config.ts.
    allowedHosts: ['admin.belikelocal.club'],
    hmr: { clientPort: 443, protocol: 'wss' },
    proxy: {
      '/auth/': { target: process.env.CMS_AUTH_PROXY_TARGET ?? 'http://nginx:8085', xfwd: true },
      '/api': { target: process.env.CMS_API_PROXY_TARGET ?? 'http://nginx:8084', xfwd: true },
    },
  },
})
