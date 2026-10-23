// Shared SVG marks for the BeLikeLocal logo sketches.
// Each function returns an SVG string drawn on a 100×100 box.
const C = {
  honey: '#F4A62A',
  honeyDeep: '#E08A12',
  ink: '#2A1F17',
  cream: '#FFF7EC',
  coral: '#EE6A4D',
  leaf: '#3E8E6A',
}

function hexPath(cx, cy, r, pointy = true) {
  const pts = []
  for (let i = 0; i < 6; i++) {
    const a = (Math.PI / 180) * (60 * i + (pointy ? 30 : 0))
    pts.push([cx + r * Math.cos(a), cy + r * Math.sin(a)])
  }
  return 'M' + pts.map((p) => p.map((n) => n.toFixed(2)).join(' ')).join('L') + 'Z'
}

function heartPath(cx, cy, s) {
  // Heart roughly s wide, centred on cx, cy.
  const k = s / 20
  const x = (n) => (cx + n * k).toFixed(2)
  const y = (n) => (cy + n * k).toFixed(2)
  return `M${x(0)} ${y(8)} C${x(-2)} ${y(6)} ${x(-10)} ${y(1)} ${x(-10)} ${y(-4)} C${x(-10)} ${y(-8)} ${x(-7)} ${y(-10)} ${x(-4.5)} ${y(-10)} C${x(-2.5)} ${y(-10)} ${x(-0.8)} ${y(-8.8)} ${x(0)} ${y(-7)} C${x(0.8)} ${y(-8.8)} ${x(2.5)} ${y(-10)} ${x(4.5)} ${y(-10)} C${x(7)} ${y(-10)} ${x(10)} ${y(-8)} ${x(10)} ${y(-4)} C${x(10)} ${y(1)} ${x(2)} ${y(6)} ${x(0)} ${y(8)}Z`
}

// A small geometric bee facing right. (cx, cy) = body centre, s = scale (1 ≈ 30px long).
function bee(cx, cy, s = 1, { ink = C.ink, body = C.honey, wing = '#fff', rot = -15 } = {}) {
  return `<g transform="translate(${cx} ${cy}) rotate(${rot}) scale(${s})">
    <ellipse cx="-3" cy="-9" rx="6.5" ry="8.5" transform="rotate(-25 -3 -9)" fill="${wing}" stroke="${ink}" stroke-width="2.2"/>
    <ellipse cx="4" cy="-9.5" rx="5.5" ry="7.5" transform="rotate(20 4 -9.5)" fill="${wing}" stroke="${ink}" stroke-width="2.2"/>
    <ellipse cx="0" cy="0" rx="13" ry="8.5" fill="${body}" stroke="${ink}" stroke-width="2.4"/>
    <path d="M-4 -8.2 Q-6 0 -4 8.2" stroke="${ink}" stroke-width="3" fill="none"/>
    <path d="M2.5 -8.4 Q0.5 0 2.5 8.4" stroke="${ink}" stroke-width="3" fill="none"/>
    <path d="M-13 0 L-17 0" stroke="${ink}" stroke-width="2.4" stroke-linecap="round"/>
    <circle cx="8.5" cy="-1.5" r="1.6" fill="${ink}"/>
    <path d="M10 -7 Q13 -12 16 -12" stroke="${ink}" stroke-width="1.8" fill="none" stroke-linecap="round"/>
  </g>`
}

// Korean finger heart: thumb crossing the index finger, a heart popping out.
function fingerHeart({ ink = C.ink, skin = C.honey, heart = C.coral, gap = C.cream, withHeart = true } = {}) {
  return `
    <g stroke-linecap="round" stroke-linejoin="round">
      <!-- curled fingers (fist) -->
      <path d="M33 62 C33 55 38 52 44 53 L60 55 C68 56 72 62 71 70 L69 84 C68 92 61 96 53 96 L45 96 C37 96 32 90 32 82 Z" fill="${skin}" stroke="${ink}" stroke-width="4"/>
      <path d="M38 70 L60 72 M38 80 L58 82" stroke="${ink}" stroke-width="3" fill="none"/>
      <!-- index finger, pointing up and slightly left -->
      <path d="M52 58 C50 48 49 40 47 31" stroke="${ink}" stroke-width="15" fill="none"/>
      <path d="M52 58 C50 48 49 40 47 31" stroke="${skin}" stroke-width="7.5" fill="none"/>
      <!-- thumb, crossing over the index -->
      <path d="M40 60 C46 50 52 42 58 34" stroke="${gap}" stroke-width="21" fill="none"/>
      <path d="M40 60 C46 50 52 42 58 34" stroke="${ink}" stroke-width="15" fill="none"/>
      <path d="M40 60 C46 50 52 42 58 34" stroke="${skin}" stroke-width="7.5" fill="none"/>
    </g>
    ${withHeart ? `<path d="${heartPath(53, 15, 22)}" fill="${heart}" stroke="${ink}" stroke-width="3"/>` : ''}`
}

// Heart built from honeycomb cells. Odd rows are shifted half a cell right.
function honeyHeart(cx, cy, r, { fill = C.honey, ink = C.ink, accent = C.coral, accentCell = [2, 4], stroke = 2 } = {}) {
  const rows = ['_XX_XX_', 'XXXXXX', '_XXXXX_', '_XXXX_', '__XXX__', '__XX__', '___X___']
  const w = Math.sqrt(3) * r
  const h = 1.5 * r
  let out = ''
  const totalH = h * (rows.length - 1)
  rows.forEach((row, ri) => {
    const shift = ri % 2 ? w / 2 : 0
    ;[...row].forEach((ch, ci) => {
      if (ch !== 'X') return
      const x = cx + (ci - 3) * w + shift
      const y = cy - totalH / 2 + ri * h
      const isAccent = accentCell && accentCell[0] === ri && accentCell[1] === ci
      out += `<path d="${hexPath(x, y, r * 0.94)}" fill="${isAccent ? accent : fill}" stroke="${ink}" stroke-width="${stroke}" stroke-linejoin="round"/>`
    })
  })
  return out
}

// Seven-cell hive with connected nodes: people + places, a heart in the middle.
function hiveNetwork({ ink = C.ink, line = C.honeyDeep, fill = C.honey, heart = C.coral, bg = 'none' } = {}) {
  const r = 15
  const w = Math.sqrt(3) * r
  const centres = [
    [50, 50],
    [50 + w, 50], [50 - w, 50],
    [50 + w / 2, 50 - 1.5 * r], [50 - w / 2, 50 - 1.5 * r],
    [50 + w / 2, 50 + 1.5 * r], [50 - w / 2, 50 + 1.5 * r],
  ]
  let cells = centres.map(([x, y], i) => `<path d="${hexPath(x, y, r - 1.2)}" fill="${i === 0 ? fill : bg}" stroke="${ink}" stroke-width="2.4" stroke-linejoin="round"/>`).join('')
  const links = [[1, 3], [3, 4], [4, 2], [2, 6], [6, 5], [5, 1], [1, 0], [2, 0], [3, 0], [6, 0]]
  let lines = links.map(([a, b]) => `<line x1="${centres[a][0]}" y1="${centres[a][1]}" x2="${centres[b][0]}" y2="${centres[b][1]}" stroke="${line}" stroke-width="2"/>`).join('')
  let dots = centres.slice(1).map(([x, y], i) => `<circle cx="${x}" cy="${y}" r="${i % 2 ? 3.6 : 4.6}" fill="${i % 3 === 0 ? heart : ink}"/>`).join('')
  return cells + lines + dots + `<path d="${heartPath(50, 50.5, 15)}" fill="${heart}" stroke="${ink}" stroke-width="2"/>`
}

// Map pin whose head is a hexagon cell holding a heart.
function hexPin({ ink = C.ink, fill = C.honey, heart = C.coral, inner = C.cream } = {}) {
  return `
    <path d="M50 96 L36 70 L64 70 Z" fill="${fill}" stroke="${ink}" stroke-width="4" stroke-linejoin="round"/>
    <path d="${hexPath(50, 42, 33)}" fill="${fill}" stroke="${ink}" stroke-width="4" stroke-linejoin="round"/>
    <path d="${hexPath(50, 42, 21)}" fill="${inner}" stroke="${ink}" stroke-width="3" stroke-linejoin="round"/>
    <path d="${heartPath(50, 43, 22)}" fill="${heart}"/>`
}

// Bee whose dotted flight path loops into a heart.
function beeTrail({ ink = C.ink, trail = C.honeyDeep } = {}) {
  return `
    <path d="M50 88 C38 78 12 62 12 40 C12 26 22 18 32 18 C41 18 47 24 50 32 C53 24 59 18 68 18 C76 18 82 23 84 30"
      fill="none" stroke="${trail}" stroke-width="3.2" stroke-dasharray="0.1 7" stroke-linecap="round"/>
    ${bee(84, 38, 1.15, { ink, rot: 70 })}`
}

function svg(inner, size = 100, vb = '0 0 100 100') {
  return `<svg viewBox="${vb}" width="${size}" height="${size}" xmlns="http://www.w3.org/2000/svg">${inner}</svg>`
}
