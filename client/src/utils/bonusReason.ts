import { t } from '@/i18n'
import { levelLabel } from '@/utils/levels'

/**
 * Why a bonus was granted (`bonus_grants.reason`), in words: a level, reviews,
 * or our team. Its own module so only the pages that show bonuses (Profile's
 * LevelPanel, Payments) need the `profile.level` text.
 */
export function bonusReason(reason: string): string {
  // Level grants carry the role since 054 (`guide_level_4`).
  const [kind, n] = reason.replace(/^(guest|guide)_/, '').split('_')
  if (kind === 'level') return t('profile.level.reason_level', { level: levelLabel(Number(n)) })
  if (kind === 'reviews') return t('profile.level.reason_reviews', { count: Number(n) })
  // Granted by the team in the CMS (`staff_<id>`).
  if (kind === 'staff') return t('profile.level.reason_staff')
  return ''
}
