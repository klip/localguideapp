<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter, type LocationQuery } from 'vue-router'
import PanelCard from '@/components/PanelCard.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { t, tn } from '@/i18n'
import {
  useApi,
  type BonusRecord,
  type PaymentEntry,
  type PaymentHistory,
  type PaymentHistoryQuery,
} from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useMatchesStore } from '@/stores/matches'
import { anchorTo, useMessagesStore } from '@/stores/messages'
import { formatMoney, formatWhen } from '@/utils/bookingTime'
import { bonusReason } from '@/utils/bonusReason'
import { percent } from '@/utils/levels'
import { reportApiError } from '@/utils/reportApiError'

/**
 * Payments & bonuses (`/payments`, either role — `paymentHistory`):
 *
 *   traveller  picks bought / used / left (and a free-picks period), a Buy
 *              button; pack purchases and guide fees paid, with refunds
 *   guide      fees travellers paid, the platform fee and payout, refunds
 *   both       every bonus of the role's kind — ready to start (Start now),
 *              queued, running, ended or withdrawn by the team
 *
 * The payments come a page at a time (10 / 20 / 50) and can be narrowed to a
 * period — today, the past week, month or three months, or a custom range of
 * days; no period shows everything. Both live in the URL (`?range=month&per=20
 * &page=2`, `?range=custom&from=2026-10-01&to=2026-10-15`) so a reload or a
 * shared link keeps them. Ranges are the viewer's own calendar days.
 *
 * The header's picks button leads here from every page but Discover.
 */
const api = useApi()
const auth = useAuthStore()
const matchesStore = useMatchesStore()
const messages = useMessagesStore()

const history = ref<PaymentHistory | null>(null)
const loading = ref(true)
const failed = ref(false)
const claiming = ref<number | null>(null)

const isGuest = computed(() => auth.activeRole !== 'guide')

/** `paymentHistory` dates are ISO 8601 UTC (`…Z`), unlike bookings' `YYYY-MM-DD HH:MM:SS`. */
function isoDate(value: string | null | undefined): Date | null {
  if (!value) return null
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? null : date
}
const picks = computed(() => history.value?.picks ?? null)
const freeUntil = computed(() => {
  const until = isoDate(picks.value?.freePicksUntil ?? null)
  return until && until.getTime() > Date.now() ? until : null
})

/** Sums over the whole selected period (the server's), not just this page. */
const totals = computed(() => history.value?.totals ?? { paid: 0, refunded: 0, received: 0 })

// --- Period and paging, kept in the URL ----------------------------------------
const route = useRoute()
const router = useRouter()

type Range = '' | 'today' | 'week' | 'month' | 'quarter' | 'custom'
const RANGES: Range[] = ['', 'today', 'week', 'month', 'quarter', 'custom']
const PER_PAGE = [10, 20, 50] as const

const one = (q: LocationQuery, key: string) => {
  const v = q[key]
  return typeof v === 'string' ? v : ''
}
const isDay = (v: string) => /^\d{4}-\d{2}-\d{2}$/.test(v)

const range = computed<Range>(() => {
  const r = one(route.query, 'range') as Range
  return RANGES.includes(r) ? r : ''
})
const customFrom = computed(() => (isDay(one(route.query, 'from')) ? one(route.query, 'from') : ''))
const customTo = computed(() => (isDay(one(route.query, 'to')) ? one(route.query, 'to') : ''))
const perPage = computed(() => {
  const n = Number(one(route.query, 'per'))
  return (PER_PAGE as readonly number[]).includes(n) ? n : PER_PAGE[0]
})
const page = computed(() => Math.max(1, Math.floor(Number(one(route.query, 'page')) || 1)))
const pages = computed(() =>
  Math.max(1, Math.ceil((history.value?.total ?? 0) / (history.value?.perPage ?? perPage.value))),
)

/** Midnight at the start of a local day, `days` days after `date`. */
function startOfDay(date: Date, days = 0) {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate() + days)
}
function fromInput(day: string) {
  const [y, m, d] = day.split('-').map(Number) as [number, number, number]
  return new Date(y, m - 1, d)
}

/** The period as UNIX seconds, from its first local midnight up to (not incl.) the day after it. */
const bounds = computed<{ from?: number; to?: number }>(() => {
  const now = new Date()
  const tomorrow = startOfDay(now, 1)
  const secs = (d: Date) => Math.floor(d.getTime() / 1000)
  switch (range.value) {
    case 'today':
      return { from: secs(startOfDay(now)), to: secs(tomorrow) }
    case 'week':
      return { from: secs(startOfDay(now, -6)), to: secs(tomorrow) }
    case 'month':
      return {
        from: secs(new Date(now.getFullYear(), now.getMonth() - 1, now.getDate() + 1)),
        to: secs(tomorrow),
      }
    case 'quarter':
      return {
        from: secs(new Date(now.getFullYear(), now.getMonth() - 3, now.getDate() + 1)),
        to: secs(tomorrow),
      }
    case 'custom':
      return {
        ...(customFrom.value ? { from: secs(fromInput(customFrom.value)) } : {}),
        ...(customTo.value ? { to: secs(startOfDay(fromInput(customTo.value), 1)) } : {}),
      }
    default:
      return {}
  }
})

/** Changes the period or page size (back to page 1), or the page, in the URL. */
function setQuery(change: Record<string, string | number | null>, keepPage = false) {
  const query: Record<string, string> = {}
  for (const [k, v] of Object.entries({ ...route.query, ...change })) {
    if (typeof v === 'string' && v !== '') query[k] = v
    else if (typeof v === 'number') query[k] = String(v)
  }
  if (!keepPage) delete query.page
  if (query.range !== 'custom') {
    delete query.from
    delete query.to
  }
  if (query.per === String(PER_PAGE[0])) delete query.per
  if (query.page === '1') delete query.page
  void router.replace({ query })
}

const historyQuery = computed<PaymentHistoryQuery>(() => ({
  ...bounds.value,
  page: page.value,
  perPage: perPage.value,
}))
const firstShown = computed(() =>
  history.value?.total ? (history.value.page - 1) * history.value.perPage + 1 : 0,
)
const lastShown = computed(() =>
  history.value
    ? Math.min(history.value.total, firstShown.value + history.value.entries.length - 1)
    : 0,
)

let requested = 0
async function load() {
  const mine = ++requested
  loading.value = true
  failed.value = false
  try {
    const result = await api.paymentHistory(historyQuery.value)
    // A slower answer for a period the viewer has already left is dropped.
    if (mine !== requested) return
    history.value = result
  } catch (err) {
    failed.value = true
    reportApiError(err, t('payments.page.load_failed'))
  } finally {
    if (mine === requested) loading.value = false
  }
}

async function claim(bonus: BonusRecord, event: Event) {
  if (claiming.value) return
  const placement = anchorTo('element', event.currentTarget as HTMLElement)
  claiming.value = bonus.id
  try {
    await api.claimBonus({ bonusId: bonus.id })
    messages.success(t('payments.bonuses.claimed'), placement)
    if (isGuest.value) matchesStore.applyPaymentState(await api.paymentState())
    await load()
  } catch (err) {
    reportApiError(err, t('payments.bonuses.claim_failed'), placement)
  } finally {
    claiming.value = null
  }
}

const day = (iso: string | null | undefined) => {
  const d = isoDate(iso ?? null)
  return d
    ? new Intl.DateTimeFormat(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
      }).format(d)
    : ''
}

function entryTitle(e: PaymentEntry) {
  if (e.type === 'pack') return t('payments.history.pack', { size: e.packSize ?? 0 })
  const name = e.counterpart?.name || t('payments.history.someone')
  return isGuest.value
    ? t('payments.history.fee_paid', { name })
    : t('payments.history.fee_received', { name })
}

function entryStatus(e: PaymentEntry) {
  if (e.type === 'pack') return t(`payments.history.pack_${e.status === 'paid' ? 'paid' : 'other'}`)
  if (e.status === 'confirmed' && e.tripStatus === 'finished')
    return t('payments.history.status_finished')
  if (e.status === 'confirmed' && e.tripStatus === 'started')
    return t('payments.history.status_started')
  return t(`payments.history.status_${e.status}`)
}

function bonusState(b: BonusRecord) {
  switch (b.state) {
    case 'queued':
      return t('payments.bonuses.state_queued', { date: day(b.startsAt) })
    case 'active':
      return t('payments.bonuses.state_active', { date: formatWhen(isoDate(b.endsAt)) ?? '' })
    case 'used':
      return t('payments.bonuses.state_used', { date: day(b.endsAt) })
    case 'revoked':
      return t('payments.bonuses.state_revoked')
    default:
      return t('payments.bonuses.state_available')
  }
}

watch(historyQuery, load, { immediate: true, deep: true })
</script>

<template>
  <section class="section">
    <div class="container narrow">
      <SectionTitle
        :eyebrow="t('payments.page.eyebrow')"
        :heading="t('payments.page.title')"
        :lead="isGuest ? t('payments.page.lead_guest') : t('payments.page.lead_guide')"
      />

      <p v-if="loading && !history" aria-busy="true" class="muted">
        {{ t('payments.page.loading') }}
      </p>
      <p v-else-if="failed && !history" class="muted">{{ t('payments.page.load_failed') }}</p>

      <template v-else-if="history">
        <PanelCard v-if="picks" :title="t('payments.picks.title')" data-test="picks">
          <div class="stats">
            <div class="stat">
              <span class="stat-value">{{ picks.capacity }}</span>
              <span class="stat-label">{{ t('payments.picks.bought') }}</span>
            </div>
            <div class="stat">
              <span class="stat-value">{{ picks.picksUsed }}</span>
              <span class="stat-label">{{ t('payments.picks.used') }}</span>
            </div>
            <div class="stat stat--left">
              <span class="stat-value" data-test="picks-left">{{ picks.selectionsLeft }}</span>
              <span class="stat-label">{{ t('payments.picks.left') }}</span>
            </div>
          </div>
          <p v-if="freeUntil" class="note free" data-test="free-picks">
            {{ t('payments.picks.free_on', { date: formatWhen(freeUntil) ?? '' }) }}
          </p>
          <p v-if="picks.freePicksUsed" class="tiny muted">
            {{ t('payments.picks.free_used', { count: picks.freePicksUsed }) }}
          </p>
          <p v-if="!picks.capacity" class="tiny muted">{{ t('payments.picks.none') }}</p>
          <RouterLink to="/unlock" role="button" class="buy" data-test="buy-picks">
            {{ t('payments.picks.buy', { size: picks.packSize, price: formatMoney(5) }) }}
          </RouterLink>
        </PanelCard>

        <PanelCard :title="t('payments.bonuses.title')" data-test="bonuses">
          <p v-if="!history.bonuses.length" class="tiny muted">
            {{ isGuest ? t('payments.bonuses.empty_guest') : t('payments.bonuses.empty_guide') }}
          </p>
          <ul v-else class="rows">
            <li
              v-for="b in history.bonuses"
              :key="b.id"
              class="row"
              :class="`bonus--${b.state}`"
              :data-bonus="b.state"
            >
              <div class="row-main">
                <strong>{{ tn(`profile.level.bonus_${b.kind}`, b.days) }}</strong>
                <span class="tiny muted"
                  >{{ bonusReason(b.reason)
                  }}<template v-if="b.grantedAt"> · {{ day(b.grantedAt) }}</template></span
                >
              </div>
              <div class="row-side">
                <span class="pill" :class="`pill--${b.state}`">{{ bonusState(b) }}</span>
                <button
                  v-if="b.state === 'available'"
                  type="button"
                  class="claim"
                  :aria-busy="claiming === b.id"
                  :disabled="claiming !== null"
                  @click="claim(b, $event)"
                >
                  {{ t('payments.bonuses.claim') }}
                </button>
              </div>
            </li>
          </ul>
        </PanelCard>

        <PanelCard :title="t('payments.history.title')" data-test="history">
          <div class="filters" data-test="history-filters">
            <label>
              {{ t('payments.filters.range') }}
              <select
                :value="range"
                data-test="range"
                @change="setQuery({ range: ($event.target as HTMLSelectElement).value })"
              >
                <option v-for="r in RANGES" :key="r || 'all'" :value="r">
                  {{ t(`payments.filters.range_${r || 'all'}`) }}
                </option>
              </select>
            </label>
            <template v-if="range === 'custom'">
              <label>
                {{ t('payments.filters.from') }}
                <input
                  type="date"
                  :value="customFrom"
                  :max="customTo || undefined"
                  data-test="custom-from"
                  @change="setQuery({ from: ($event.target as HTMLInputElement).value })"
                />
              </label>
              <label>
                {{ t('payments.filters.to') }}
                <input
                  type="date"
                  :value="customTo"
                  :min="customFrom || undefined"
                  data-test="custom-to"
                  @change="setQuery({ to: ($event.target as HTMLInputElement).value })"
                />
              </label>
            </template>
            <label class="per-page">
              {{ t('payments.filters.per_page') }}
              <select
                :value="perPage"
                data-test="per-page"
                @change="setQuery({ per: ($event.target as HTMLSelectElement).value })"
              >
                <option v-for="n in PER_PAGE" :key="n" :value="n">{{ n }}</option>
              </select>
            </label>
          </div>
          <p v-if="!history.entries.length" class="tiny muted" data-test="history-empty">
            {{ range ? t('payments.history.empty_range') : t('payments.history.empty') }}
          </p>
          <template v-else>
            <p class="totals tiny" data-test="totals">
              <template v-if="isGuest"
                >{{ t('payments.history.total_paid', { amount: formatMoney(totals.paid) }) }}
                <template v-if="totals.refunded">
                  ·
                  {{
                    t('payments.history.total_refunded', { amount: formatMoney(totals.refunded) })
                  }}</template
                ></template
              >
              <template v-else
                >{{
                  t('payments.history.total_received', { amount: formatMoney(totals.received) })
                }}
                <template v-if="totals.refunded">
                  ·
                  {{
                    t('payments.history.total_refunded_guests', {
                      amount: formatMoney(totals.refunded),
                    })
                  }}</template
                ></template
              >
            </p>
            <ul class="rows" :aria-busy="loading">
              <li v-for="e in history.entries" :key="`${e.type}-${e.id}`" class="row">
                <div class="row-main">
                  <strong>
                    <RouterLink
                      v-if="e.counterpart"
                      :to="
                        isGuest ? `/guides/${e.counterpart.id}` : `/visitors/${e.counterpart.id}`
                      "
                      >{{ entryTitle(e) }}</RouterLink
                    ><template v-else>{{ entryTitle(e) }}</template>
                  </strong>
                  <span class="tiny muted"
                    >{{ day(e.at)
                    }}<template v-if="e.meetingAt">
                      ·
                      {{
                        t('payments.history.meeting', {
                          when: formatWhen(isoDate(e.meetingAt)) ?? '',
                        })
                      }}</template
                    >
                    · {{ entryStatus(e) }}</span
                  >
                  <span v-if="e.payout != null && e.commissionAmount != null" class="tiny muted">{{
                    t('payments.history.platform_fee', {
                      fee: formatMoney(e.commissionAmount),
                      rate: percent(e.commissionRate ?? 0),
                      payout: formatMoney(e.payout),
                    })
                  }}</span>
                  <span v-if="e.refundAmount" class="tiny refund">{{
                    t('payments.history.refunded', {
                      amount: formatMoney(e.refundAmount),
                      date: day(e.refundedAt),
                    })
                  }}</span>
                </div>
                <span class="amount" :class="{ 'amount--in': !isGuest }">
                  {{ isGuest ? '−' : '+' }}{{ formatMoney(e.amount) }}
                </span>
              </li>
            </ul>
            <nav
              v-if="history.total > history.perPage"
              class="pager"
              :aria-label="t('payments.pager.label')"
              data-test="pager"
            >
              <button
                type="button"
                class="secondary outline"
                :disabled="history.page <= 1 || loading"
                data-test="prev"
                @click="setQuery({ page: history.page - 1 }, true)"
              >
                <span class="rtl-flip" aria-hidden="true">←</span> {{ t('payments.pager.prev') }}
              </button>
              <span class="tiny muted" aria-live="polite" data-test="page-info">
                {{ t('payments.pager.page', { page: history.page, pages }) }} ·
                {{
                  t('payments.pager.showing', {
                    from: firstShown,
                    to: lastShown,
                    total: history.total,
                  })
                }}
              </span>
              <button
                type="button"
                class="secondary outline"
                :disabled="history.page >= pages || loading"
                data-test="next"
                @click="setQuery({ page: history.page + 1 }, true)"
              >
                {{ t('payments.pager.next') }} <span class="rtl-flip" aria-hidden="true">→</span>
              </button>
            </nav>
          </template>
        </PanelCard>
      </template>
    </div>
  </section>
</template>

<style scoped lang="scss">
.narrow {
  max-width: 46rem;
  display: grid;
  gap: 1.25rem;
}

.stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}

.stat {
  display: grid;
  justify-items: center;
  padding: 0.75rem 0.5rem;
  border-radius: 0.9rem;
  background: var(--rg-orange-50, #fff4ec);
}

.stat--left {
  background: #e9f6ee;
}

.stat-value {
  font-size: 1.6rem;
  font-weight: 800;
  line-height: 1.1;
}

.stat-label {
  font-size: 0.8rem;
  color: var(--pico-muted-color);
}

.free {
  margin: 0 0 0.5rem;
  color: #1f7a45;
}

.buy {
  width: auto;
  margin-top: 0.5rem;
}

.rows {
  margin: 0;
  padding: 0;
  list-style: none;
}

.row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.7rem 0;
  border-bottom: 1px solid var(--rg-line);
  list-style: none;

  &:last-child {
    border-bottom: 0;
  }
}

.row-main {
  display: grid;
  gap: 0.15rem;
  min-width: 0;
}

.row-side {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-shrink: 0;
}

.pill {
  font-size: 0.75rem;
  padding: 0.15rem 0.6rem;
  border-radius: 999px;
  background: #f1f1f1;
  white-space: nowrap;
}

.pill--available {
  background: #fff4d6;
}

.pill--active {
  background: #e9f6ee;
  color: #1f7a45;
}

.pill--revoked {
  text-decoration: line-through;
}

.claim {
  width: auto;
  margin: 0;
  padding: 0.3rem 0.8rem;
  font-size: 0.85rem;
}

.totals {
  margin: 0 0 0.25rem;
}

.filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.5rem 0.75rem;
  margin-bottom: 0.75rem;

  label {
    display: grid;
    gap: 0.2rem;
    margin: 0;
    font-size: 0.8rem;
    color: var(--pico-muted-color);
  }

  select,
  input {
    width: auto;
    min-width: 8rem;
    margin: 0;
    padding-block: 0.35rem;
    font-size: 0.9rem;
  }
}

.per-page {
  margin-inline-start: auto !important;

  select {
    min-width: 5rem;
  }
}

.pager {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin-top: 0.75rem;

  button {
    width: auto;
    margin: 0;
    padding: 0.35rem 0.9rem;
    font-size: 0.85rem;
  }
}

ul[aria-busy='true'] {
  opacity: 0.55;
}

.amount {
  font-weight: 700;
  white-space: nowrap;
}

.amount--in {
  color: #1f7a45;
}

.refund {
  color: #1f7a45;
}

@media (max-width: 520px) {
  .row {
    flex-wrap: wrap;
  }
}
</style>
