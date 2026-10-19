import { t } from '@/i18n'

/** "3 km away", or "Under 1 km away" for 0 (the server rounds to whole km). */
export function formatDistance(km: number): string {
  return km < 1 ? t('common.distance.under_one') : t('common.distance.away', { km })
}
