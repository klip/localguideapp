<script setup lang="ts">
import { computed } from 'vue'
import { t, tn } from '@/i18n'
import type { Standing } from '@/types'
import { levelLabel } from '@/utils/levels'

/**
 * The badges a person has earned — ◆ their level (from successful trips,
 * `Levels.php`), ✓ Verified (identity checked), ★ Top rated
 * (≥ 5 reviews averaging ≥ 4.6) and their finished trips — on deck cards and
 * profile pages. The same facts that move them up the deck (`Ranking.php`),
 * so a visitor can see *why* someone is near the top. Presentational: renders
 * nothing when there's nothing to show.
 */
const props = withDefaults(defineProps<{ standing: Standing; onPhoto?: boolean }>(), {
  onPhoto: false,
})

const badges = computed(() => {
  const list: { key: string; icon: string; label: string; title: string }[] = []
  // A guide who has only guided for free shows that instead of a level;
  // their first paid trip brings the real one.
  if (props.standing.volunteer) {
    list.push({
      key: 'volunteer',
      icon: '♡',
      label: t('common.standing.volunteer'),
      title: t('common.standing.volunteer_title'),
    })
  } else if (props.standing.level > 0) {
    list.push({
      key: 'level',
      icon: '◆',
      label: levelLabel(props.standing.level),
      title: t('common.standing.level_title'),
    })
  }
  if (props.standing.verified) {
    list.push({
      key: 'verified',
      icon: '✓',
      label: t('common.standing.verified'),
      title: t('common.standing.verified_title'),
    })
  }
  if (props.standing.topRated) {
    list.push({
      key: 'top',
      icon: '★',
      label: t('common.standing.top_rated'),
      title: t('common.standing.top_rated_title'),
    })
  }
  if (props.standing.tripsCompleted > 0) {
    const label = tn('common.standing.trips', props.standing.tripsCompleted)
    list.push({ key: 'trips', icon: '⛰', label, title: label })
  }
  return list
})
</script>

<template>
  <ul
    v-if="badges.length"
    class="standing"
    :class="{ 'standing--on-photo': onPhoto }"
    :aria-label="t('common.standing.label')"
  >
    <li
      v-for="badge in badges"
      :key="badge.key"
      class="standing-badge"
      :class="`standing-badge--${badge.key}`"
      :title="badge.title"
      :data-test="`standing-${badge.key}`"
    >
      <span aria-hidden="true">{{ badge.icon }}</span> {{ badge.label
      }}<span v-if="badge.title !== badge.label" class="visually-hidden">: {{ badge.title }}</span>
    </li>
  </ul>
</template>

<style scoped lang="scss">
.standing {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
}

.standing-badge {
  list-style: none;
  margin: 0;
  padding: 0.15rem 0.55rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 700;
  line-height: 1.4;
  background: var(--rg-orange-50);
  border: 1px solid var(--rg-orange-200);
  color: var(--rg-orange-ink);
}

.standing-badge--level {
  background: var(--rg-orange-500);
  border-color: var(--rg-orange-500);
  color: #fff;
}

.standing-badge--verified {
  background: var(--rg-green-soft);
  border-color: var(--rg-green-border);
  color: var(--rg-green);
}

// Readable over the photo's dark scrim on deck cards.
.standing--on-photo .standing-badge {
  background: rgba(255, 255, 255, 0.92);
}

.standing--on-photo .standing-badge--level {
  background: var(--rg-orange-500);
}
</style>
