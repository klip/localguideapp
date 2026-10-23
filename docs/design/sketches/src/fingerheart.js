// A better finger-heart glyph (🫰-style): fist, index finger up, thumb
// crossing over its tip. Drawn as filled shapes with an ink outline.
function fh2({ ink = '#2A1F17', skin = '#F4A62A', nail = '#FFF7EC', gap = '#FFF7EC', heart = '#EE6A4D', heartShape = 'heart', sw = 4 } = {}) {
  // fist: three curled fingers as stacked rounded bumps
  const fist = `
    <path d="M30 66 C30 58 36 55 43 56 L64 58 C72 59 76 65 75 72 L73 86 C72 93 66 97 58 97 L44 97 C35 97 29 91 29 83 Z"
      fill="${skin}" stroke="${ink}" stroke-width="${sw}" stroke-linejoin="round"/>
    <path d="M34 73 Q50 70 70 75 M33 84 Q48 81 68 86" fill="none" stroke="${ink}" stroke-width="${sw * 0.7}" stroke-linecap="round"/>`
  // index: from the fist up to a tip leaning left
  const index = `<path d="M48 60 C47 48 44 38 39 28 C37 23 40 18 45 18 C49 18 51 21 53 26 C57 37 60 48 61 59 Z"
      fill="${skin}" stroke="${ink}" stroke-width="${sw}" stroke-linejoin="round"/>`
  // thumb: from the side of the fist up across the index tip, leaning right
  const thumb = `
    <path d="M33 62 C38 50 47 39 57 31 C61 28 66 29 67 33 C68 37 66 40 63 43 C55 51 48 58 44 66 Z"
      fill="${skin}" stroke="${gap}" stroke-width="${sw * 2.6}" stroke-linejoin="round"/>
    <path d="M33 62 C38 50 47 39 57 31 C61 28 66 29 67 33 C68 37 66 40 63 43 C55 51 48 58 44 66 Z"
      fill="${skin}" stroke="${ink}" stroke-width="${sw}" stroke-linejoin="round"/>
    <path d="M58 33.5 C61 32 64 32.5 64.5 35 C65 37 63.5 38.6 61.5 39.5 Z" fill="${nail}" stroke="${ink}" stroke-width="${sw * 0.55}" stroke-linejoin="round"/>`
  let pop = ''
  if (heartShape === 'heart') pop = `<path d="${heartPath(70, 13, 20)}" fill="${heart}" stroke="${ink}" stroke-width="${sw * 0.75}"/>`
  if (heartShape === 'hex') pop = `<path d="${hexPath(71, 14, 11)}" fill="${heart}" stroke="${ink}" stroke-width="${sw * 0.75}" stroke-linejoin="round"/><path d="${heartPath(71, 14.5, 11)}" fill="#FFF7EC"/>`
  if (heartShape === 'bee') pop = bee(72, 14, 0.75, { ink, rot: -10 })
  return fist + index + thumb + pop
}
