/**
 * A loose, hand-drawn honeycomb mesh as one SVG path — the brand texture
 * behind the logo (docs/design/sketches/09-logo-inline-tilted.png). The
 * jitter comes from a fixed seed, so it's the same drawing on every load.
 */
export function honeycombPath(width: number, height: number, radius: number, seed = 5): string {
  let s = seed
  const rnd = () => (s = (s * 9301 + 49297) % 233280) / 233280
  const cellWidth = Math.sqrt(3) * radius
  let d = ''
  for (let row = -1; row * 1.5 * radius < height + radius; row++) {
    for (let col = -1; col * cellWidth < width + cellWidth; col++) {
      const cx = col * cellWidth + (row % 2 ? cellWidth / 2 : 0)
      const cy = row * 1.5 * radius
      // A few cells left out, so it reads as drawn rather than tiled.
      if (rnd() < 0.1) continue
      const jitter = () => (rnd() - 0.5) * radius * 0.12
      const points: string[] = []
      for (let i = 0; i < 6; i++) {
        const a = (Math.PI / 180) * (60 * i + 30)
        points.push(
          `${(cx + radius * Math.cos(a) + jitter()).toFixed(1)} ${(cy + radius * Math.sin(a) + jitter()).toFixed(1)}`,
        )
      }
      d += `M${points.join('L')}Z`
    }
  }
  return d
}
