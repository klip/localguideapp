<script setup lang="ts">
import { ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import PanelCard from '@/components/PanelCard.vue'
import PillBadge from '@/components/PillBadge.vue'
import PriceBox from '@/components/PriceBox.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { useApi } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { anchorTo, useMessagesStore } from '@/stores/messages'
import { PACK_SIZE, useShortlistStore } from '@/stores/shortlist'
import { reportApiError } from '@/utils/reportApiError'

const router = useRouter()
const api = useApi()
const auth = useAuthStore()
const messages = useMessagesStore()
const shortlist = useShortlistStore()
const { hasAccess, capacity } = storeToRefs(shortlist)

const paying = ref(false)
const panelRef = ref<HTMLElement | null>(null)

// Stands in for the payment provider callback — calling api.unlockPack() *is*
// the payment (see api/classes/Payments.php, no real gateway wired up yet).
// First purchase sends the visitor through profile setup; a top-up pack
// returns them to the deck they left.
async function pay() {
  if (paying.value) return

  if (!auth.user) {
    messages.error('Log in first to unlock a pack.', anchorTo('element', panelRef.value))
    return
  }

  paying.value = true
  try {
    const isTopUp = hasAccess.value
    const state = await api.unlockPack()
    shortlist.setPacksUnlocked(state.packsUnlocked)
    router.push(isTopUp ? '/discover' : '/onboarding')
  } catch (err) {
    reportApiError(err, 'Could not complete payment.', anchorTo('element', panelRef.value))
  } finally {
    paying.value = false
  }
}
</script>

<template>
  <section class="section shell">
    <SectionTitle
      eyebrow="Screen 01"
      heading="Unlock your shortlist"
      :lead="`Choose up to ${PACK_SIZE} guides. No subscription — pay once and start matching.`"
    />

    <div ref="panelRef">
      <PanelCard
        class="narrow"
        title="Unlock your shortlist"
        :subtitle="`Choose up to ${PACK_SIZE} guides.`"
      >
        <PriceBox compact>
          <div class="price-head">
            <div>
              <span class="tiny muted">ONE-TIME FEE</span>
              <div class="price">£5</div>
            </div>
            <PillBadge>{{ PACK_SIZE }} picks</PillBadge>
          </div>
        </PriceBox>

        <button type="button" class="full" :aria-busy="paying" :disabled="paying" @click="pay">
          {{ paying ? 'Paying…' : 'Pay £5 securely' }}
        </button>

        <p class="tiny muted">
          You can add {{ PACK_SIZE }} more guide selections later for another £5.
        </p>

        <p v-if="hasAccess" class="tiny">
          You already have {{ capacity }} selections unlocked.
          <RouterLink to="/discover">Back to discovery</RouterLink>
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
