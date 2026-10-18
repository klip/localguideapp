<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import PanelCard from './PanelCard.vue'
import ReviewForm from './ReviewForm.vue'
import ReviewItem from './ReviewItem.vue'
import ReviewSummary from './ReviewSummary.vue'
import ReviewsDialog from './ReviewsDialog.vue'
import { t, tn } from '@/i18n'
import { useApi, type ReviewsPage, type Role } from '@/plugins/api'
import { reportApiError } from '@/utils/reportApiError'

/**
 * The review block at the foot of both public profile pages
 * (`GuideProfilePage.vue`, `VisitorProfilePage.vue`): the summary header,
 * the `PREVIEW_COUNT` newest reviews, "Show all" (→ `ReviewsDialog`), and —
 * when the server says the viewer is eligible — the write/edit form.
 *
 * One `api.reviews()` call feeds all of it; a save or delete simply refetches,
 * so the average, the list and the form never disagree.
 */
const props = defineProps<{
  userId: number
  subjectName: string
  /** The side this page shows them on — the tab open first. */
  role: Role
  /** Every role they hold: with both, the other side's reviews are a tab away. */
  roles?: Role[]
}>()

/** Ratings are per role; someone who is both has a tab for each. */
const shownRole = ref<Role>(props.role)
const tabs = computed<Role[]>(() =>
  props.roles?.length === 2 ? [props.role, otherRole(props.role)] : [],
)

function otherRole(role: Role): Role {
  return role === 'guide' ? 'guest' : 'guide'
}

function showRole(role: Role) {
  if (role === shownRole.value) return
  shownRole.value = role
  page.value = null
  void load()
}

const PREVIEW_COUNT = 3

const api = useApi()
const page = ref<ReviewsPage | null>(null)
const failed = ref(false)
const dialogOpen = ref(false)
const showAllButton = ref<HTMLButtonElement | null>(null)

async function load() {
  try {
    page.value = await api.reviews({
      userId: props.userId,
      role: shownRole.value,
      limit: PREVIEW_COUNT,
    })
    failed.value = false
  } catch (err) {
    failed.value = true
    reportApiError(err, t('common.reviews.load_failed'))
  }
}

onMounted(load)
watch(() => props.userId, load)

async function closeDialog() {
  dialogOpen.value = false
  await nextTick()
  showAllButton.value?.focus()
}
</script>

<template>
  <PanelCard class="reviews" :title="t('common.reviews.title')">
    <div
      v-if="tabs.length"
      role="tablist"
      :aria-label="t('common.reviews.tabs_label')"
      class="role-tabs"
    >
      <button
        v-for="tab in tabs"
        :key="tab"
        type="button"
        role="tab"
        class="role-tab"
        :class="{ 'role-tab--on': shownRole === tab }"
        :aria-selected="shownRole === tab"
        :data-test="`reviews-tab-${tab}`"
        @click="showRole(tab)"
      >
        {{ t(tab === 'guide' ? 'common.reviews.tab_guide' : 'common.reviews.tab_guest') }}
      </button>
    </div>
    <p v-if="failed" class="muted">{{ t('common.reviews.unavailable') }}</p>
    <p v-else-if="!page" class="tiny muted">{{ t('common.reviews.loading') }}</p>

    <template v-else>
      <template v-if="page.total > 0">
        <ReviewSummary :average="page.average" :total="page.total" :histogram="page.histogram" />

        <div class="list">
          <ReviewItem v-for="review in page.reviews" :key="review.id" :review="review" />
        </div>

        <button
          ref="showAllButton"
          type="button"
          class="soft-btn show-all"
          @click="dialogOpen = true"
        >
          {{ tn('common.reviews.show_all', page.total) }}
        </button>

        <ReviewsDialog
          :user-id="userId"
          :role="shownRole"
          :subject-name="subjectName"
          :open="dialogOpen"
          @close="closeDialog"
        />
      </template>

      <p v-else class="muted">{{ t('common.reviews.none') }}</p>

      <ReviewForm
        v-if="page.viewer.canReview"
        :subject-id="userId"
        :role="shownRole"
        :subject-name="subjectName"
        :existing="page.viewer.myReview"
        @saved="load"
        @deleted="load"
      />
    </template>
  </PanelCard>
</template>

<style scoped lang="scss">
.reviews {
  margin-top: 1.25rem;
}

.list {
  margin-top: 1rem;
}

.role-tabs {
  display: flex;
  gap: 0.25rem;
  margin-bottom: 1rem;
  border-bottom: 1px solid var(--rg-line);
}

.role-tab {
  width: auto;
  margin: 0 0 -1px;
  padding: 0.4rem 0.9rem;
  border: 0;
  border-bottom: 2px solid transparent;
  border-radius: 0;
  background: none;
  color: var(--rg-muted);
  font-size: 0.9rem;

  &:hover,
  &:focus-visible {
    background: none;
    color: var(--rg-ink);
  }
}

.role-tab--on {
  border-bottom-color: var(--pico-primary);
  color: var(--rg-ink);
  font-weight: 600;
}

.show-all {
  width: auto;
  margin-top: 0.5rem;
}
</style>
