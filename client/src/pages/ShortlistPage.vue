<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import PanelCard from '@/components/PanelCard.vue'
import PillBadge from '@/components/PillBadge.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import TagList from '@/components/TagList.vue'
import { useApi, type MatchRow } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useCategoriesStore } from '@/stores/categories'
import { useGuidesStore } from '@/stores/guides'
import { anchorTo, useMessagesStore } from '@/stores/messages'
import { PACK_SIZE, useShortlistStore } from '@/stores/shortlist'
import { reportApiError } from '@/utils/reportApiError'

/**
 * Destination of the "View shortlist" button. Contact details stay hidden until
 * interest is mutual, so each row shows match state rather than a phone number.
 */
const shortlist = useShortlistStore()
const { picks, capacity, selectionsLeft } = storeToRefs(shortlist)

const categoriesStore = useCategoriesStore()
const guidesStore = useGuidesStore()
const pickedGuides = computed(() => picks.value.map(guidesStore.find).filter((guide) => !!guide))

const api = useApi()
const auth = useAuthStore()
const messages = useMessagesStore()

/**
 * Real, server-confirmed matches (`selections.active = 1`) — distinct from
 * `pickedGuides` above (this visitor's own local pick/pass decisions,
 * client-side only). A guide showing up here means *they've* also marked
 * this visitor as interested.
 */
const matches = ref<MatchRow[]>([])
const loadingMatches = ref(false)
const revokingId = ref<number | null>(null)

async function loadMatches() {
  if (!auth.user) return

  loadingMatches.value = true
  try {
    const result = await api.matches()
    matches.value = result.matches
  } catch (err) {
    reportApiError(err, 'Could not load your matches.')
  } finally {
    loadingMatches.value = false
  }
}

async function revokeMatch(match: MatchRow, event: MouseEvent) {
  if (!auth.user || revokingId.value !== null) return

  // Captured up front (before the row can be removed from the DOM) so the
  // toast still lands where the row was, regardless of how the request
  // resolves.
  const rowEl = (event.currentTarget as HTMLElement | null)?.closest('li')
  const rowPlacement = anchorTo('element', rowEl)

  revokingId.value = match.counterpart_id
  try {
    await api.revokeSelection({ targetId: match.counterpart_id })
    matches.value = matches.value.filter((item) => item.id !== match.id)
    messages.success(
      `Unmatched from ${match.name || match.email || 'your match'}. You're free to be matched by someone else.`,
      rowPlacement,
    )
  } catch (err) {
    reportApiError(err, 'Could not revoke this match.', rowPlacement)
  } finally {
    revokingId.value = null
  }
}

onMounted(() => {
  loadMatches()
  // Every card on this page is one the visitor already picked, so the deck's
  // "hide what you've decided on" filter has to be opted out of here.
  guidesStore.load(undefined, { includeDecided: true })
  // Labels for the tag chips below (categories are cached after the first load).
  categoriesStore.load()
})
</script>

<template>
  <section class="section shell">
    <SectionTitle
      eyebrow="Your picks"
      heading="Shortlist"
      lead="Guides you kept. Once one of them marks you as interested too, their contact details unlock here."
    />

    <div class="grid-2">
      <PanelCard v-for="guide in pickedGuides" :key="guide.id">
        <template #header>
          <div class="row-between">
            <div>
              <strong>{{ guide.name }}, {{ guide.age }}</strong>
              <div class="tiny muted">{{ guide.headline }}</div>
            </div>
            <PillBadge tone="neutral">Awaiting reply</PillBadge>
          </div>
        </template>

        <div class="row-between">
          <strong>{{ guide.priceLabel }}</strong>
          <RouterLink class="tiny" :to="{ name: 'guide-profile', params: { id: guide.id } }">
            Full profile →
          </RouterLink>
        </div>

        <TagList :tags="categoriesStore.tagsFor(guide.attributes)" />

        <template #footer>
          <button type="button" class="soft-btn full" @click="shortlist.remove(guide.id)">
            Remove from shortlist
          </button>
        </template>
      </PanelCard>
    </div>

    <PanelCard v-if="!pickedGuides.length" class="narrow" title="Nothing picked yet">
      <p class="muted">
        Head back to discovery and keep the guides you want to hear from — you have
        {{ selectionsLeft }} of {{ capacity || PACK_SIZE }} selections available.
      </p>
      <RouterLink to="/discover" role="button">Back to discovery</RouterLink>
    </PanelCard>

    <p v-else class="tiny muted spread">
      {{ picks.length }} of {{ capacity }} selections used.
      <RouterLink to="/unlock">Unlock {{ PACK_SIZE }} more for £5</RouterLink>
    </p>

    <PanelCard
      class="matches-panel"
      title="Confirmed matches"
      subtitle="Mutual interest, from the server. Revoke one and you're free to be matched by someone else."
    >
      <p v-if="loadingMatches" class="tiny muted">Loading…</p>

      <ul v-else-if="matches.length" class="picked">
        <li v-for="match in matches" :key="match.id" class="row-between">
          <span>{{ match.name || match.email || 'Your match' }}</span>
          <button
            type="button"
            class="soft-btn"
            :aria-busy="revokingId === match.counterpart_id"
            :disabled="revokingId === match.counterpart_id"
            @click="revokeMatch(match, $event)"
          >
            Revoke
          </button>
        </li>
      </ul>

      <p v-else class="tiny muted">No confirmed matches yet.</p>

      <template #footer>
        <RouterLink to="/matches" class="tiny">Manage all matches — incoming, pending, passed →</RouterLink>
      </template>
    </PanelCard>
  </section>
</template>

<style scoped lang="scss">
.spread {
  margin-top: 1.25rem;
}

.matches-panel {
  margin-top: 1.25rem;
}

.picked {
  padding-left: 0;
  list-style: none;
  margin-bottom: 0;

  li {
    padding: 0.4rem 0;

    & + li {
      border-top: 1px solid var(--rg-line);
    }
  }
}
</style>
