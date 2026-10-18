import { t } from '@/i18n'
import type { LevelName } from '@/plugins/api'

/**
 * Level names in order (index = level), matching `Levels::NAMES` — the
 * thresholds are there too: 1, 3, 6, 12, 24, 48 and 96 successful trips.
 */
export const LEVEL_NAMES: LevelName[] = [
  'new',
  'starter',
  'explorer',
  'pathfinder',
  'trailblazer',
  'pioneer',
  'legend',
  'icon',
]

export function levelName(level: number): string {
  const key = LEVEL_NAMES[Math.max(0, Math.min(LEVEL_NAMES.length - 1, level))]!
  return t(`common.level.name_${key}`)
}

/** "Level 3 · Pathfinder". */
export function levelLabel(level: number): string {
  return t('common.level.label', { level, name: levelName(level) })
}

/** A 0–1 rate as a whole percentage, e.g. 0.12 → "12%". */
export function percent(rate: number): string {
  return `${Math.round(rate * 100)}%`
}
