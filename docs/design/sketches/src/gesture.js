// v3 mark: the finger heart (🫰) with a small bee, no heart.
// Anatomy, palm side: the THUMB is the big finger pointing up; only the tip
// of the INDEX peeks out behind it to the left; the other fingers curl into
// a fist whose top edge slopes down to the right, with creases between them.
// (`nail`/`gap` are accepted for compatibility with older callers.)
function gesture({ ink = '#2A1F17', skin = '#F4A62A', nail, gap, withBee = true, sw = 4, beeWing = '#fff', creases = true } = {}) {
  // Index fingertip, behind the hand (its right end hides under the thumb).
  const index = `
    <path d="M44 17 C35 12 25 9 18 12 C12.5 15 13 22 18 27.5 C24 34 30 39 37 44 Z"
      fill="${skin}" stroke="${ink}" stroke-width="${sw}" stroke-linejoin="round"/>
    ${creases ? `<path d="M31 14.5 Q27.5 22 28 31" fill="none" stroke="${ink}" stroke-width="${sw * 0.6}" stroke-linecap="round"/>` : ''}`
  // Thumb + palm + curled fingers as one silhouette.
  const hand = `
    <path d="M47 7.5 C50 3 58 3 60.5 8 C62.5 12 61.5 15.5 59.5 19.5 L51 37
             L82 49 C88 51.5 91 56 91 62 L90 84 C89 93 82 98 73 98 L52 97
             C40 96 31 92 27 84 C22 74 21 60 24 46 C27 34 36 20 47 7.5 Z"
      fill="${skin}" stroke="${ink}" stroke-width="${sw}" stroke-linejoin="round"/>
    ${creases ? `<path d="M51 37 C47 44 45.5 51 45.5 59 M65 42.5 C65 50 64 56 63 63 M79 48 C79 55 78 61 77 67"
      fill="none" stroke="${ink}" stroke-width="${sw * 0.75}" stroke-linecap="round"/>` : ''}`
  const b = withBee ? `
    <path d="M63 11 C66 6 70 5 73 6" fill="none" stroke="${ink}" stroke-width="${sw * 0.6}" stroke-dasharray="0.1 ${sw * 1.4}" stroke-linecap="round"/>
    ${bee(81, 8, 0.62, { ink, rot: -12, wing: beeWing })}` : ''
  return index + hand + b
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
