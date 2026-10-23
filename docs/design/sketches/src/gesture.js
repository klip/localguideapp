// v2 mark: crossed fingers (K-pop finger gesture) with a small bee, no heart.
// Fist and index finger are ONE outline, so the finger grows out of the fist;
// the thumb is a second shape laid across the index near its tip.
function gesture({ ink = '#2A1F17', skin = '#F4A62A', nail = '#FFF7EC', gap = '#FFF7EC', withBee = true, sw = 4, beeWing = '#fff' } = {}) {
  // Fist + index finger as one outline: the index leans left out of the
  // right half of the fist.
  const hand = `
    <path d="M34 97 C27 95 25 88 26 80 L27 68 C27 61 31 58 37 58 L49.6 58
             L33.6 19 A8 8 0 0 1 48.4 13 L66.4 57
             C70.5 58 75 63 75 70 L74 84 C73 92 67 97 59 97 Z"
      fill="${skin}" stroke="${ink}" stroke-width="${sw}" stroke-linejoin="round"/>
    <path d="M45 72 Q59 70 73 74 M43 84 Q57 82 72 86" fill="none" stroke="${ink}" stroke-width="${sw * 0.7}" stroke-linecap="round"/>
    <path d="M36.5 20 Q40.5 16.5 45 17.5" fill="none" stroke="${ink}" stroke-width="${sw * 0.55}" stroke-linecap="round"/>`
  // Thumb: from the left side of the fist, leaning right, crossing the index near its tip.
  const thumbPath = `M26.5 76 L30.4 58.7 L52.4 20.7 A6.5 6.5 0 0 1 63.6 27.3 L45 59.5 C42 65 41 72 42 78 C36 80 30 79 26.5 76 Z`
  // The cream separating outline only where the thumb lies over the index
  // (above the fist), so the hand itself stays in one piece.
  const clipId = 'gc' + Math.random().toString(36).slice(2, 8)
  const thumb = `
    <clipPath id="${clipId}"><rect x="0" y="0" width="100" height="52"/></clipPath>
    <path d="${thumbPath}" fill="${skin}" stroke="${gap}" stroke-width="${sw * 2.4}" stroke-linejoin="round" clip-path="url(#${clipId})"/>
    <path d="${thumbPath}" fill="${skin}" stroke="${ink}" stroke-width="${sw}" stroke-linejoin="round"/>
    <ellipse cx="58.2" cy="23.8" rx="3.6" ry="2.6" transform="rotate(-60 58.2 23.8)" fill="${nail}" stroke="${ink}" stroke-width="${sw * 0.55}"/>`
  const b = withBee ? `
    <path d="M66 19 C69 12 73 10 76 11" fill="none" stroke="${ink}" stroke-width="${sw * 0.6}" stroke-dasharray="0.1 ${sw * 1.4}" stroke-linecap="round"/>
    ${bee(83, 11, 0.62, { ink, rot: -12, wing: beeWing })}` : ''
  return hand + thumb + b
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
