// v3 mark: the finger heart (🫰) with a small bee, no heart.
// Anatomy, palm side: the THUMB is the big finger pointing up; only the tip
// of the INDEX peeks out behind it to the left; the other fingers curl into
// a fist whose top edge slopes down to the right, with creases between them.
// (`nail`/`gap` are accepted for compatibility with older callers.)
function gesture({ ink = '#2A1F17', skin = '#F4A62A', nail, gap, withBee = true, sw = 4, beeWing = '#fff', creases = true, rot = 0, thumbLine = true } = {}) {
  // Index fingertip, behind the hand (its right end hides under the thumb).
  const index = `
    <path d="M44 17 C35 11.5 25 8.5 18 11.5 C11.5 14.5 11.8 22.5 17.5 28 C23.5 34 30 39 37 44 Z"
      fill="${skin}" stroke="${ink}" stroke-width="${sw}" stroke-linejoin="round"/>
    ${creases ? `<path d="M31 14.5 Q27.5 22 28 31" fill="none" stroke="${ink}" stroke-width="${sw * 0.6}" stroke-linecap="round"/>` : ''}`
  // Thumb + palm + curled fingers as one silhouette.
  const hand = `
    <path d="M46.5 8 C50.5 2 59.5 2 61.5 8.5 C63 13 61.5 16.5 59.5 20.5 L53.5 33.5
             C52.3 36.3 53.6 38.6 56.5 39.6 L80 48.2 C87.5 51 92 56.5 92 63.5 L91.5 81
             C91 91.5 83.5 98.5 73 98.5 L54 98.2 C42 97.8 31.5 93.5 26.5 84.5
             C21 74 20.5 60 23.8 46.5 C27 33.5 35.5 20.5 46.5 8 Z"
      fill="${skin}" stroke="${ink}" stroke-width="${sw}" stroke-linejoin="round"/>
    ${creases ? `<path d="${thumbLine ? "M54.5 39.8 C49 45.5 46.8 52 46.8 59.5 " : ""}M66 43.5 C66 50.5 65 56.5 64 62.5 M79.5 48.5 C79.5 55 78.7 60.5 77.8 66"
      fill="none" stroke="${ink}" stroke-width="${sw * 0.75}" stroke-linecap="round"/>` : ''}`
  const b = withBee ? `
    <path d="M${63 + rot * 0.8} 11 C${66 + rot * 0.8} 6 ${70 + rot * 0.8} 5 ${73 + rot * 0.8} 6" fill="none" stroke="${ink}" stroke-width="${sw * 0.6}" stroke-dasharray="0.1 ${sw * 1.4}" stroke-linecap="round"/>
    ${bee(81 + rot * 0.8, 8, 0.62, { ink, rot: -12, wing: beeWing })}` : ''
  return (rot ? `<g transform="rotate(${rot} 55 60)">${index + hand}</g>` : index + hand) + b
}

// A loose, hand-drawn honeycomb used behind the logo.
function honeySketch(w, h, r, { color = '#E9C48A', opacity = 0.55, seed = 3 } = {}) {
  let s = seed
  const rnd = () => ((s = (s * 9301 + 49297) % 233280) / 233280)
  const cw = Math.sqrt(3) * r
  let out = ''
  for (let row = -1; row * 1.5 * r < h + r; row++) {
    for (let col = -1; col * cw < w + cw; col++) {
      const cx = col * cw + (row % 2 ? cw / 2 : 0)
      const cy = row * 1.5 * r
      // fade toward the edges
      const dx = (cx - w / 2) / (w / 2), dy = (cy - h / 2) / (h / 2)
      const d = Math.sqrt(dx * dx + dy * dy)
      if (d > 1.05 || rnd() < 0.12) continue
      const jitter = () => (rnd() - 0.5) * r * 0.12
      const pts = []
      for (let i = 0; i < 6; i++) {
        const a = (Math.PI / 180) * (60 * i + 30)
        pts.push(`${(cx + r * Math.cos(a) + jitter()).toFixed(1)} ${(cy + r * Math.sin(a) + jitter()).toFixed(1)}`)
      }
      const op = (opacity * (1 - d * 0.85)).toFixed(2)
      out += `<path d="M${pts.join('L')}Z" fill="none" stroke="${color}" stroke-width="${(1.2 + rnd() * 0.8).toFixed(2)}" stroke-linejoin="round" stroke-dasharray="${rnd() < 0.3 ? `${(r * 1.6).toFixed(0)} ${(r * 0.35).toFixed(0)}` : 'none'}" opacity="${op}"/>`
    }
  }
  return out
}

// Stroke icon in the style of the app's NavIcon: a handshake (Lucide, ISC licence).
const ICON_HANDSHAKE = '<path d="m11 17 2 2a1 1 0 1 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="m21 3 1 11h-2"/><path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/>'
