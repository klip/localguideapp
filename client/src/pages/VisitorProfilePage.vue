<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import PanelCard from '@/components/PanelCard.vue'
import ReviewsSection from '@/components/ReviewsSection.vue'
import StandingBadges from '@/components/StandingBadges.vue'
import ReportUser from '@/components/ReportUser.vue'
import TagList from '@/components/TagList.vue'
import { t } from '@/i18n'
import { ApiError, useApi, type PublicProfile } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useCategoriesStore } from '@/stores/categories'
import { formatRange, rangeFormatFor } from '@/utils/formatRange'
import { reportApiError } from '@/utils/reportApiError'
import { formatRating } from '@/utils/reviews'
import { standingOf } from '@/utils/standing'

/**
 * A guest's public profile — the guest-side counterpart of
 * `GuideProfilePage.vue`, reached from the guide deck's "Full profile →".
 * Everything here comes from `api.publicProfile()`, which never carries
 * contact details — nothing does: matched people talk in Messages. No
 * accept/reject here — the guide decides from the deck.
 */
const props = defineProps<{ id: string }>()

const api = useApi()
const auth = useAuthStore()
const categoriesStore = useCategoriesStore()

const visitor = ref<PublicProfile | null>(null)
const loading = ref(true)

async function loadVisitor() {
  loading.value = true
  visitor.value = null
  try {
    const profile = await api.publicProfile(Number(props.id), 'guest')
    // A guide's id under /visitors/ is "not found", not a visitor page for them.
    visitor.value = (profile.roles ?? [profile.role]).includes('guest') ? profile : null
  } catch (err) {
    if (!(err instanceof ApiError && err.status === 400))
      reportApiError(err, t('visitor_profile.toast.load_failed'))
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void loadVisitor()
  categoriesStore.load()
})
watch(() => props.id, loadVisitor)

const name = computed(() => visitor.value?.name || t('common.visitor_card.default_name'))
const photo = computed(() =>
  visitor.value?.image ? `url("${visitor.value.image}")` : 'var(--rg-photo-visitor)',
)
const genderLabel = computed(() =>
  visitor.value?.gender ? t(`common.gender.${visitor.value.gender}`) : null,
)
const backTo = computed(() => (auth.activeRole === 'guide' ? '/guide/discover' : '/'))
</script>

<template>
  <section class="section shell">
    <p class="tiny">
      <RouterLink :to="backTo"
        ><span class="rtl-flip" aria-hidden="true">←</span>
        {{ t('visitor_profile.page.back') }}</RouterLink
      >
    </p>

    <PanelCard v-if="loading" :title="t('visitor_profile.page.loading')" />

    <template v-else-if="visitor">
      <div class="profile-layout">
        <div class="hero-photo" :style="{ backgroundImage: `var(--rg-photo-scrim), ${photo}` }">
          <div class="hero-copy">
            <strong class="hero-name"
              >{{ name }}<template v-if="visitor.age">, {{ visitor.age }}</template></strong
            >
            <span v-if="visitor.review_count > 0">
              {{ formatRating(visitor.rating_average ?? 0, visitor.review_count) }}
            </span>
            <StandingBadges :standing="standingOf(visitor)" on-photo />
          </div>
        </div>

        <PanelCard
          :title="visitor.party || t('visitor_profile.details.default_title')"
          :subtitle="
            visitor.duration_hours
              ? t('visitor_profile.details.duration', { hours: visitor.duration_hours })
              : undefined
          "
        >
          <dl v-if="genderLabel" class="facts">
            <dt>{{ t('visitor_profile.details.gender') }}</dt>
            <dd>{{ genderLabel }}</dd>
          </dl>

          <h3 class="sub">{{ t('visitor_profile.details.about') }}</h3>
          <p>{{ visitor.bio || t('visitor_profile.details.no_bio') }}</p>

          <template v-for="(values, key) in visitor.attributes" :key="key">
            <h3 v-if="values.length" class="sub">{{ categoriesStore.labelFor(String(key)) }}</h3>
            <TagList
              v-if="values.length"
              :tags="values.map((value) => categoriesStore.optionLabelFor(String(key), value))"
            />
          </template>

          <template v-for="(spans, key) in visitor.ranges" :key="`range-${key}`">
            <h3 v-if="spans.length" class="sub">{{ categoriesStore.labelFor(String(key)) }}</h3>
            <TagList
              v-if="spans.length"
              :tags="spans.map((span) => formatRange(span, rangeFormatFor(String(key))))"
            />
          </template>
        </PanelCard>
      </div>

      <ReviewsSection
        :user-id="visitor.id"
        :subject-name="name"
        role="guest"
        :roles="visitor.roles ?? [visitor.role]"
      />
      <ReportUser :subject-id="visitor.id" :subject-name="name" />
    </template>

    <PanelCard v-else :title="t('visitor_profile.not_found.title')">
      <p class="muted">{{ t('visitor_profile.not_found.body') }}</p>
      <RouterLink :to="backTo" role="button">{{ t('visitor_profile.not_found.back') }}</RouterLink>
    </PanelCard>
  </section>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

.profile-layout {
  display: grid;
  gap: 1.25rem;

  @include bp.lg {
    grid-template-columns: 1fr 1fr;
    align-items: start;
  }
}

.hero-photo {
  aspect-ratio: 4 / 4.35;
  border-radius: var(--rg-radius-card);
  background-size: cover;
  background-position: center;
  position: relative;
  box-shadow: var(--rg-shadow);
}

.hero-copy {
  position: absolute;
  inset: auto 1.25rem 1.25rem;
  color: #fff;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.hero-name {
  font-size: 2rem;
  line-height: 1.05;
}

.facts {
  display: flex;
  gap: 0.5rem;
  margin: 0 0 0.5rem;

  dt {
    font-weight: 600;
  }

  dd {
    margin: 0;
  }
}

.sub {
  font-size: 0.95rem;
  margin: 1rem 0 0.25rem;
}
</style>
