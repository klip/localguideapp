<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import PanelCard from '@/components/PanelCard.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { t, tn } from '@/i18n'
import { useApi, type BonusRecord, type PaymentEntry, type PaymentHistory } from '@/plugins/api'
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

/** Paid in (guest) or received (guide), net of refunds. */
const totals = computed(() => {
  let paid = 0
  let refunded = 0
  let payout = 0
  for (const e of history.value?.entries ?? []) {
    if (e.status !== 'failed') paid += e.amount
    refunded += e.refundAmount ?? 0
    if (!e.refundAmount && e.payout) payout += e.payout
  }
  return { paid, refunded, payout }
})

async function load() {
  loading.value = true
  failed.value = false
  try {
    history.value = await api.paymentHistory()
  } catch (err) {
    failed.value = true
    reportApiError(err, t('payments.page.load_failed'))
  } finally {
    loading.value = false
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

onMounted(load)
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
          <p v-if="!history.entries.length" class="tiny muted">{{ t('payments.history.empty') }}</p>
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
                >{{ t('payments.history.total_received', { amount: formatMoney(totals.payout) }) }}
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
            <ul class="rows">
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
