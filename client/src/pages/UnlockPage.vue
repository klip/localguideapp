<script setup lang="ts">
import { ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import PanelCard from '@/components/PanelCard.vue'
import PillBadge from '@/components/PillBadge.vue'
import PriceBox from '@/components/PriceBox.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { t } from '@/i18n'
import { useApi } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { anchorTo, useMessagesStore } from '@/stores/messages'
import { PACK_SIZE, useMatchesStore } from '@/stores/matches'
import { reportApiError } from '@/utils/reportApiError'

const router = useRouter()
const api = useApi()
const auth = useAuthStore()
const messages = useMessagesStore()
const matchesStore = useMatchesStore()
const { hasAccess, capacity } = storeToRefs(matchesStore)

const paying = ref(false)
const panelRef = ref<HTMLElement | null>(null)

// Stands in for the payment provider callback — calling api.unlockPack() *is*
// the payment (see api/classes/Payments.php, no real gateway wired up yet).
// First purchase sends the visitor to their profile, to fill it in before
// guides see it; a top-up pack returns them to the deck they left.
async function pay() {
  if (paying.value) return

  if (!auth.user) {
    messages.error(t('unlock.toast.log_in_first'), anchorTo('element', panelRef.value))
    return
  }

  paying.value = true
  try {
    const isTopUp = hasAccess.value
    const state = await api.unlockPack()
    matchesStore.applyPaymentState(state)
    router.push(isTopUp ? '/discover' : '/profile')
  } catch (err) {
    reportApiError(err, t('unlock.toast.payment_failed'), anchorTo('element', panelRef.value))
  } finally {
    paying.value = false
  }
}
</script>

<template>
  <section class="section shell">
    <SectionTitle
      :eyebrow="t('unlock.header.eyebrow')"
      :heading="t('unlock.header.heading')"
      :lead="t('unlock.header.lead', { count: PACK_SIZE })"
    />

    <div ref="panelRef">
      <PanelCard
        class="narrow"
        :title="t('unlock.panel.title')"
        :subtitle="t('unlock.panel.subtitle', { count: PACK_SIZE })"
      >
        <PriceBox compact>
          <div class="price-head">
            <div>
              <span class="tiny muted">{{ t('unlock.price.one_time_fee') }}</span>
              <div class="price">£5</div>
            </div>
            <PillBadge>{{ t('unlock.price.picks', { count: PACK_SIZE }) }}</PillBadge>
          </div>
        </PriceBox>

        <button type="button" class="full" :aria-busy="paying" :disabled="paying" @click="pay">
          {{ paying ? t('unlock.panel.paying') : t('unlock.panel.pay') }}
        </button>

        <p class="tiny muted">
          {{ t('unlock.panel.top_up_note', { count: PACK_SIZE }) }}
        </p>

        <p v-if="hasAccess" class="tiny">
          {{ t('unlock.panel.already_unlocked', { count: capacity }) }}
          <RouterLink to="/discover">{{ t('unlock.panel.back_to_discovery') }}</RouterLink>
        </p>
      </PanelCard>
    </div>
  </section>
</template>

<style scoped lang="scss">
.price-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
}

button {
  margin-bottom: 0.75rem;
}
</style>
