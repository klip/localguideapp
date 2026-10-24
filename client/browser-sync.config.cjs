// The app on a phone on the same Wi-Fi (development only): `yarn phone` in
// the lemp-node container, then open https://<the Mac's Wi-Fi address>:3002
// on the phone and accept the certificate warning once.
//
// browser-sync serves HTTPS (its own self-signed certificate — HTTPS is
// needed for the sign-in cookies and the device's location) and proxies the
// Vite dev server, WebSockets included. The page then talks to the API
// through the same address: `/ws` and `/auth/` are forwarded by Vite
// (vite.config.ts, src/plugins/socket.ts). Port 3002 is published in
// docker-lemp's docker-compose.yaml (3000 is Grafana's).
module.exports = {
  proxy: {
    target: 'http://localhost:5173',
    ws: true,
    // Leave cookies alone: browser-sync's "strip domain" rewrite keeps only
    // name=value and HttpOnly, dropping `Secure` — and a browser refuses a
    // `__Host-` cookie (the sign-in ones) without it. Ours have no Domain.
    cookies: { stripDomain: false },
  },
  https: true,
  port: 3002,
  // Its control panel isn't needed for this (and browser-sync keeps the next
  // port, 3003, for itself).
  ui: false,
  open: false,
  notify: false,
  // Each device browses on its own (no mirrored clicks or scrolling).
  ghostMode: false,
  // Vite's own hot reload points at port 443 (nginx), which the phone can't
  // reach: browser-sync reloads the phone's page when the source changes.
  files: ['src/**/*', 'index.html'],
  reloadDelay: 300,
}
