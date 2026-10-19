<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'
import { t, tn } from '@/i18n'
import { useApi, type BonusGrant, type LevelState } from '@/plugins/api'
import { useMatchesStore } from '@/stores/matches'
import { anchorTo, useMessagesStore } from '@/stores/messages'
import { levelLabel, percent } from '@/utils/levels'
import { reportApiError } from '@/utils/reportApiError'

/**
 * "Your level" on the profile page (`Levels.php`): a level badge in the page
 * header with a "?" beside it. Hovering the "?" (or clicking it — touch,
 * keyboard) opens a popover with the details: progress to the next level,
 * what the level gives (picks per pack for a visitor, the platform fee for a
 * guide), bonus packages to claim when wanted, and any sanction lowering it.
 * Calls the API itself, like `ReviewsSection`.
 */
const props = defineProps<{ role: 'guest' | 'guide' }>()

const api = useApi()
const messages = useMessagesStore()
const matchesStore = useMatchesStore()

const root = ref<HTMLElement | null>(null)
const trigger = ref<HTMLButtonElement | null>(null)
const popoverId = useId()
const state = ref<LevelState | null>(null)
const claiming = ref<number | null>(null)

const dateFormat = new Intl.DateTimeFormat(undefined, {
  day: 'numeric',
  month: 'short',
  hour: '2-digit',
  minute: '2-digit',
})
const when = (iso: string) => dateFormat.format(new Date(iso))

const progress = computed(() => {
  const s = state.value
  if (!s || s.next_level_points === null) return null
  return {
    done: s.points,
    needed: s.next_level_points,
    // Guide points come in fractions (higher fees earn a little more).
    left: Math.round((s.next_level_points - s.points) * 100) / 100,
    // The level those trips earn — not effective + 1 while a sanction lowers the badge.
    level: s.next_level ?? s.level + 1,
  }
})

// Open while a mouse rests on the "?" or the popover (`hovering`), or once
// clicked (`pinned`) until clicked again, Escape or a click elsewhere.
const hovering = ref(false)
const pinned = ref(false)
const open = computed(() => hovering.value || pinned.value)
let leaveTimer: ReturnType<typeof setTimeout> | undefined

function onPointerEnter(event: PointerEvent) {
  // A tap fires pointerenter too, with no leave to follow — touch uses the click.
  if (event.pointerType !== 'mouse') return
  clearTimeout(leaveTimer)
  hovering.value = true
}

function onPointerLeave(event: PointerEvent) {
  if (event.pointerType !== 'mouse') return
  // A short grace period to cross the gap between the "?" and the popover.
  leaveTimer = setTimeout(() => (hovering.value = false), 150)
}

function close() {
  pinned.value = false
  hovering.value = false
}

function onKeydown(event: KeyboardEvent) {
  if (event.key !== 'Escape' || !open.value) return
  close()
  trigger.value?.focus()
}

function onDocumentPointerDown(event: PointerEvent) {
  if (root.value && !root.value.contains(event.target as Node)) close()
}

watch(pinned, (value) => {
  if (value) document.addEventListener('pointerdown', onDocumentPointerDown)
  else document.removeEventListener('pointerdown', onDocumentPointerDown)
})

async function load() {
  try {
    state.value = await api.myLevel()
  } catch (err) {
    reportApiError(err, t('profile.level.load_failed'))
  }
}

async function claim(bonus: BonusGrant, event: Event) {
  if (claiming.value) return
  const placement = anchorTo('element', event.currentTarget as HTMLElement | null)
  claiming.value = bonus.id
  try {
    state.value = await api.claimBonus({ bonusId: bonus.id })
    messages.success(t(`profile.level.claimed_${bonus.kind}`), placement)
    // Free picks change what the deck allows straight away.
    if (props.role === 'guest') matchesStore.applyPaymentState(await api.paymentState())
  } catch (err) {
    reportApiError(err, t('profile.level.claim_failed'), placement)
  } finally {
    claiming.value = null
  }
}

function bonusTitle(bonus: BonusGrant) {
  return tn(`profile.level.bonus_${bonus.kind}`, bonus.days)
}

function bonusReason(bonus: BonusGrant) {
  // Level grants carry the role since 054 (`guide_level_4`).
  const [kind, n] = bonus.reason.replace(/^(guest|guide)_/, '').split('_')
  if (kind === 'level') return t('profile.level.reason_level', { level: levelLabel(Number(n)) })
  if (kind === 'reviews') return t('profile.level.reason_reviews', { count: Number(n) })
  return ''
}

onMounted(() => {
  void load()
  // A level-up / bonus notification links to /profile#level, but the page
  // renders this badge only after loading the profile — too late for the
  // router's own scroll — so it brings itself into view, details open.
  if (window.location.hash === '#level') {
    pinned.value = true
    void nextTick(() => root.value?.scrollIntoView?.({ behavior: 'smooth', block: 'start' }))
  }
})

onBeforeUnmount(() => {
  clearTimeout(leaveTimer)
  document.removeEventListener('pointerdown', onDocumentPointerDown)
})
</script>

<template>
  <div id="level" ref="root" class="level" data-test="level-panel" @keydown="onKeydown">
    <template v-if="state">
      <span v-if="state.volunteer" class="level-badge" data-test="level-volunteer"
        >♡ {{ t('common.standing.volunteer') }}</span
      >
      <span v-else class="level-badge">◆ {{ levelLabel(state.level) }}</span>
    </template>
    <span class="level-help" @pointerenter="onPointerEnter" @pointerleave="onPointerLeave">
      <button
        ref="trigger"
        type="button"
        class="help-trigger"
        :aria-label="t('profile.level.title')"
        :aria-expanded="open"
        :aria-controls="popoverId"
        data-test="level-help"
        @click="pinned = !pinned"
      >
        ?
      </button>
      <div v-show="open" :id="popoverId" class="popover" data-test="level-popover">
        <h3 class="popover-title">{{ t('profile.level.title') }}</h3>
        <p class="tiny muted popover-subtitle">{{ t('profile.level.subtitle') }}</p>
        <p v-if="!state" class="muted">{{ t('profile.level.loading') }}</p>
        <template v-else>
          <p class="level-line">
            <span v-if="state.volunteer" class="level-badge"
              >♡ {{ t('common.standing.volunteer') }}</span
            >
            <span v-else class="level-badge">◆ {{ levelLabel(state.level) }}</span>
            <span class="tiny muted">{{ tn('profile.level.trips', state.trips_completed) }}</span>
            <span class="tiny muted" data-test="level-points">{{
              t('profile.level.points', { points: state.points })
            }}</span>
          </p>
          <p v-if="state.volunteer" class="tiny muted">{{ t('profile.level.volunteer_hint') }}</p>

          <div v-if="progress" class="progress">
            <progress
              :value="progress.done"
              :max="progress.needed"
              :aria-label="t('profile.level.progress_label')"
            />
            <span class="tiny muted">{{
              t(`profile.level.to_next_${role}`, {
                points: progress.left,
                level: levelLabel(progress.level),
              })
            }}</span>
          </div>
          <p v-else class="tiny muted">{{ t('profile.level.top') }}</p>

          <p v-if="state.sanction" class="sanction tiny" role="status">
            <strong>{{ tn('profile.level.sanction', state.sanction.levels) }}</strong>
            <template v-if="state.sanction.until">{{
              ' ' + t('profile.level.sanction_until', { date: when(state.sanction.until) })
            }}</template>
            <template v-if="state.sanction.reason">{{ ' — ' + state.sanction.reason }}</template>
          </p>

          <h4 class="perks-title">{{ t('profile.level.perks') }}</h4>
          <ul class="perks">
            <li v-if="state.picks_per_pack !== null">
              {{ t('profile.level.perk_picks', { count: state.picks_per_pack }) }}
            </li>
            <li v-if="state.commission_rate !== null">
              {{ t('profile.level.perk_fee', { rate: percent(state.commission_rate) }) }}
            </li>
            <li v-if="state.free_picks_until">
              {{ t('profile.level.free_picks_active', { date: when(state.free_picks_until) }) }}
            </li>
            <li v-if="state.no_fee_until">
              {{ t('profile.level.no_fee_active', { date: when(state.no_fee_until) }) }}
            </li>
          </ul>
          <p class="tiny muted">{{ t(`profile.level.how_${role}`) }}</p>

          <h4 class="perks-title">{{ t('profile.level.bonuses') }}</h4>
          <!-- Levels::claim() refuses and activeBonusUntil() ignores bonuses while sanctioned. -->
          <p
            v-if="state.sanction && state.bonuses.length"
            class="tiny muted"
            data-test="bonuses-paused"
          >
            {{ t('profile.level.bonuses_paused') }}
          </p>
          <p v-if="!state.bonuses.length" class="tiny muted">
            {{ t(`profile.level.no_bonuses_${role}`) }}
          </p>
          <ul v-else class="bonuses">
            <li
              v-for="bonus in state.bonuses"
              :key="bonus.id"
              class="bonus"
              :data-test="`bonus-${bonus.id}`"
            >
              <div>
                <strong>{{ bonusTitle(bonus) }}</strong>
                <span class="tiny muted">{{ bonusReason(bonus) }}</span>
              </div>
              <button
                v-if="bonus.state === 'available' && !state.sanction"
                type="button"
                :aria-busy="claiming === bonus.id || undefined"
                :disabled="claiming !== null"
                @click="claim(bonus, $event)"
              >
                {{ t('profile.level.claim') }}
              </button>
              <span v-else-if="bonus.state === 'queued' && bonus.starts_at" class="tiny muted">
                {{ t('profile.level.starts', { date: when(bonus.starts_at) }) }}
              </span>
              <span v-else-if="bonus.state === 'active' && bonus.ends_at" class="tiny state-active">
                {{ t('profile.level.active_until', { date: when(bonus.ends_at) }) }}
              </span>
              <span v-else-if="bonus.state === 'used'" class="tiny muted">{{
                t('profile.level.used')
              }}</span>
            </li>
          </ul>
        </template>
      </div>
    </span>
  </div>
</template>

<style scoped lang="scss">
.level {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  scroll-margin-top: 5rem;
}

.level-help {
  display: inline-flex;
}

.help-trigger {
  width: 1.4rem;
  height: 1.4rem;
  margin: 0;
  padding: 0;
  border: 1px solid var(--rg-line);
  border-radius: 50%;
  background: var(--rg-surface, #fff);
  color: var(--pico-muted-color);
  font-size: 0.8rem;
  font-weight: 700;
  line-height: 1;
  cursor: help;

  &[aria-expanded='true'] {
    border-color: var(--rg-orange-500);
    color: var(--rg-orange-500);
  }
}

.popover {
  position: absolute;
  top: calc(100% + 0.5rem);
  right: 0;
  z-index: 20;
  width: min(24rem, calc(100vw - 32px));
  max-height: min(70vh, 34rem);
  overflow-y: auto;
  padding: 1rem 1.1rem;
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-panel);
  background: var(--pico-card-background-color, #fff);
  box-shadow: 0 12px 32px rgba(0, 0, 0, 0.16);
  text-align: start;

  // Bridges the gap above, so the mouse can travel from the "?" into it.
  &::before {
    content: '';
    position: absolute;
    inset: -0.5rem 0 auto;
    height: 0.5rem;
  }
}

.popover-title {
  margin: 0 0 0.2rem;
  font-size: 1.05rem;
}

.popover-subtitle {
  margin: 0 0 0.75rem;
}

.level-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.6rem;
  margin: 0 0 0.6rem;
}

.level-badge {
  padding: 0.2rem 0.7rem;
  border-radius: 999px;
  background: var(--rg-orange-500);
  color: #fff;
  font-weight: 700;
}

.progress {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  margin-bottom: 0.75rem;

  progress {
    margin: 0;
  }
}

.sanction {
  padding: 0.5rem 0.75rem;
  border-radius: var(--rg-radius-soft);
  background: var(--rg-red-soft);
  border: 1px solid var(--rg-red-border);
}

.perks-title {
  margin: 1rem 0 0.4rem;
  font-size: 0.95rem;
}

.perks {
  margin: 0 0 0.4rem;
  padding-left: 1.1rem;
}

.bonuses {
  list-style: none;
  margin: 0;
  padding: 0;
}

.bonus {
  list-style: none;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.5rem 0;
  border-top: 1px solid var(--rg-line);

  div {
    display: flex;
    flex-direction: column;
  }

  button {
    width: auto;
    margin: 0;
  }
}

.state-active {
  color: var(--rg-green);
  font-weight: 700;
}
</style>
