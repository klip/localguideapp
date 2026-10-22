<script setup lang="ts">
import { formatMoney, PACK_PRICE } from '@/utils/money'
import { computed } from 'vue'
import FeatureCard from '@/components/FeatureCard.vue'
import PhonePreview from '@/components/PhonePreview.vue'
import PillBadge from '@/components/PillBadge.vue'
import PriceBox from '@/components/PriceBox.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { guides } from '@/data/guides'
import { t } from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { PACK_SIZE } from '@/stores/matches'

const auth = useAuthStore()
const heroGuide = guides[0]

// Computed so the cards follow a language switch.
const steps = computed(() =>
  (['unlock', 'filter', 'match'] as const).map((step) => ({
    title: t(`home.how.${step}_title`),
    body: t(`home.how.${step}_body`, {
      count: PACK_SIZE,
      price: formatMoney(PACK_PRICE, { trim: true }),
    }),
  })),
)
</script>

<template>
  <div>
    <section class="hero section shell">
      <div class="hero-grid">
        <div>
          <PillBadge><span aria-hidden="true">🌍</span> {{ t('home.hero.badge') }}</PillBadge>
          <h1>{{ t('home.hero.heading') }}</h1>
          <p class="lead">
            {{ t('home.hero.lead', { price: formatMoney(PACK_PRICE, { trim: true }) }) }}
          </p>

          <div class="cta-row">
            <RouterLink to="/register" role="button">{{
              t('home.hero.find_guide', { price: formatMoney(PACK_PRICE, { trim: true }) })
            }}</RouterLink>
            <RouterLink
              v-if="!auth.isAuthenticated"
              :to="{ path: '/register', query: { role: 'guide' } }"
              role="button"
              class="soft-btn"
            >
              {{ t('home.hero.im_a_guide') }}
            </RouterLink>
          </div>

          <div class="cta-row">
            <PillBadge>{{ t('home.hero.selections_included', { count: PACK_SIZE }) }}</PillBadge>
            <PillBadge>{{
              t('home.hero.more_for_price', {
                count: PACK_SIZE,
                price: formatMoney(PACK_PRICE, { trim: true }),
              })
            }}</PillBadge>
            <PillBadge>{{ t('home.hero.direct_contact') }}</PillBadge>
          </div>
        </div>

        <PhonePreview
          v-if="heroGuide"
          :guide="heroGuide"
          :progress-label="t('home.hero.preview_progress')"
        />
      </div>
    </section>

    <section id="how" class="section shell">
      <SectionTitle :eyebrow="t('home.how.eyebrow')" :heading="t('home.how.heading')" />
      <div class="grid-3">
        <FeatureCard v-for="step in steps" :key="step.title" v-bind="step" />
      </div>
    </section>

    <section class="section shell">
      <PriceBox>
        <div class="grid-3 price-grid">
          <div>
            <PillBadge>{{ t('home.pricing.badge') }}</PillBadge>
            <div class="price">{{ formatMoney(PACK_PRICE, { trim: true }) }}</div>
            <div class="muted">{{ t('home.pricing.caption') }}</div>
          </div>
          <div>
            <strong>{{ t('home.pricing.includes', { count: PACK_SIZE }) }}</strong>
            <p class="muted no-margin">
              {{ t('home.pricing.tagline') }}
            </p>
          </div>
          <div>
            <RouterLink to="/register" role="button" class="full">{{
              t('home.pricing.start')
            }}</RouterLink>
          </div>
        </div>
      </PriceBox>
    </section>
  </div>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

.hero {
  padding: {
    top: 3.5rem;
    bottom: 2.2rem;
  }

  @include bp.md {
    padding-top: 5rem;
  }
}

.hero-grid {
  display: grid;
  gap: 2rem;
  align-items: center;

  @include bp.md {
    grid-template-columns: 1.15fr 0.85fr;
  }
}

h1 {
  font-size: clamp(2.55rem, 7vw, 5.4rem);
  line-height: 0.95;
  letter-spacing: -0.055em;
  max-width: 11ch;
  margin: 1rem 0 1.1rem;
}

.lead {
  font-size: 1.05rem;
  color: var(--rg-muted);
  max-width: 52ch;
}

.cta-row {
  display: flex;
  gap: 0.7rem;
  flex-wrap: wrap;
  margin: 1.2rem 0;

  a[role='button'] {
    margin-bottom: 0;
  }
}

.price-grid {
  align-items: center;
}

.no-margin {
  margin-bottom: 0;
}
</style>
